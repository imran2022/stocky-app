<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\BaseController;
use App\Mail\AdminNewOrderMail;
use App\Mail\OnlineOrderPlaced;
use App\Models\Client;
use App\Models\OnlineOrder;
use App\Models\product_warehouse;
use App\Models\Setting;
use App\Models\StoreSetting;
use App\Models\User;
use App\Notifications\NewOnlineOrderNotification;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use App\Services\OnlineOrderInvoiceService;
use App\Services\FlutterwaveService;
use App\Services\PayPalService;
use App\Services\PaystackService;
use App\Services\RazorpayService;
use App\Services\WalletException;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class CheckoutController extends BaseController
{
    /** Payment methods the storefront may accept. */
    private const ALLOWED_PAYMENT_METHODS = ['credit_card', 'mobile_money', 'cod', 'wallet', 'paypal', 'paystack', 'flutterwave', 'razorpay'];

    /** Redirect gateways: order is created payment-pending, then approved off-site. */
    private const REDIRECT_GATEWAYS = ['paypal', 'paystack', 'flutterwave', 'razorpay'];

    public function __construct(
        private CheckoutService $checkout,
        private OnlineOrderInvoiceService $invoices,
        private WalletService $wallets,
        private PayPalService $paypal,
        private PaystackService $paystack,
        private FlutterwaveService $flutterwave,
        private RazorpayService $razorpay
    ) {
    }

    /**
     * Live quote for the cart: available shipping methods for the customer's
     * region + server-side subtotal/tax/shipping/total. Drives the checkout UI.
     */
    public function quote(Request $req)
    {
        $user = Auth::guard('store')->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $data = $req->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:1'],
            'shipping_method_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:60'],
        ]);

        $client = $user->client_id ? Client::find($user->client_id) : null;
        $country = $req->input('country') ?: ($client->country ?? null);
        $state = $req->input('state') ?: ($client->state ?? null);

        try {
            $warehouseIds = $this->checkout->activeWarehouseIds();
            $lines = $this->checkout->buildLineItems($data['items'], $warehouseIds);
            $this->assertItemsFitVehicle($lines['items']);
            $subtotal = $lines['subtotal'];

            // Coupon is lenient here: an invalid code returns a message but the
            // quote still works (so the UI can show the totals + the error).
            $discount = 0.0;
            $couponCode = null;
            $couponError = null;
            if ($req->filled('coupon_code')) {
                try {
                    $c = $this->checkout->resolveCoupon($req->input('coupon_code'), $subtotal, $user->client_id);
                    $discount = $c['discount'];
                    $couponCode = $c['coupon']->code;
                } catch (CheckoutException $e) {
                    $couponError = $e->getMessage();
                }
            }

            $netSubtotal = round(max(0, $subtotal - $discount), 2);
            $tax = $this->checkout->resolveTax($netSubtotal, $country, $state);

            $methods = $this->checkout->availableShippingMethods($country)
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'price' => (float) $m->price,
                ])->values();

            // Shipping cost only if a valid method is chosen.
            $shippingCost = 0.0;
            $chosen = $req->input('shipping_method_id');
            if ($chosen) {
                $match = $methods->firstWhere('id', (int) $chosen);
                if ($match) {
                    $shippingCost = (float) $match['price'];
                }
            }

            return response()->json([
                'subtotal' => $subtotal,
                'discount' => $discount,
                'coupon_code' => $couponCode,
                'coupon_error' => $couponError,
                'tax' => $tax['amount'],
                'tax_rate' => $tax['rate'],
                'shipping_cost' => $shippingCost,
                'total' => round($netSubtotal + $tax['amount'] + $shippingCost, 2),
                'shipping_methods' => $methods,
                'shipping_required' => $methods->isNotEmpty(),
                'country' => $country,
            ]);
        } catch (CheckoutException $e) {
            return $e->toResponse();
        }
    }

    /**
     * Create a Stripe PaymentIntent for the SERVER-computed total (never the
     * client-posted amount), so the charge always matches the order.
     */
    public function createPaymentIntent(Request $req)
    {
        $user = Auth::guard('store')->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $data = $req->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:1'],
            'shipping_method_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:60'],
        ]);

        $stripeSecret = config('services.stripe.secret');
        if (! $stripeSecret) {
            return response()->json(['error' => __('messages.StripeNotConfiguredError')], 500);
        }

        $client = $user->client_id ? Client::find($user->client_id) : null;
        $country = $req->input('country') ?: ($client->country ?? null);
        $state = $req->input('state') ?: ($client->state ?? null);

        try {
            $warehouseIds = $this->checkout->activeWarehouseIds();
            $breakdown = $this->checkout->calculate(
                $data['items'], $warehouseIds, $country, $state, $data['shipping_method_id'] ?? null,
                $data['coupon_code'] ?? null, $user->client_id
            );
            $this->assertItemsFitVehicle($breakdown['items']);
        } catch (CheckoutException $e) {
            return $e->toResponse();
        }

        if ($breakdown['total'] < 0.50) {
            return response()->json(['error' => __('messages.OrderTotalTooLow')], 422);
        }

        \Stripe\Stripe::setApiKey($stripeSecret);

        $currency = strtolower((string) (StoreSetting::query()->value('currency_code') ?: 'usd'));
        $currency = substr(preg_replace('/[^a-z]/', '', $currency) ?: 'usd', 0, 3);
        $amountInCents = (int) round($breakdown['total'] * 100);

        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount' => $amountInCents,
                'currency' => $currency,
                'metadata' => [
                    'client_id' => $user->client_id ?? $user->id,
                    'ecommerce_client_id' => $user->id,
                ],
            ]);
        } catch (\Exception $e) {
            Log::warning('Stripe PaymentIntent create failed: '.$e->getMessage());

            return response()->json(['error' => __('messages.PaymentSetupFailed')], 502);
        }

        return response()->json([
            'clientSecret' => $intent->client_secret,
            'amount' => $breakdown['total'],
            'subtotal' => $breakdown['subtotal'],
            'tax' => $breakdown['tax'],
            'shipping_cost' => $breakdown['shipping_cost'],
        ]);
    }

    public function store(Request $req)
    {
        $user = Auth::guard('store')->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $data = $req->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:1'],
            'warehouse_id' => ['nullable', 'integer'],
            'shipping_method_id' => ['nullable', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:60'],
            'payment_method' => ['required', 'string'],
            'stripe_payment_intent_id' => ['nullable', 'string', 'max:128'],

            // Customer / shipping address
            'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'shipping_address' => ['nullable', 'string', 'max:250'],
            'shipping_city' => ['nullable', 'string', 'max:100'],
            'shipping_state' => ['nullable', 'string', 'max:100'],
            'shipping_zip' => ['nullable', 'string', 'max:30'],
            'shipping_country' => ['nullable', 'string', 'max:100'],
        ]);

        $paymentMethod = $data['payment_method'];

        // ---- (5b) Payment method validation ---------------------------------
        if (! in_array($paymentMethod, self::ALLOWED_PAYMENT_METHODS, true)) {
            return response()->json(['error' => __('messages.PaymentMethodInvalid')], 422);
        }
        if ($paymentMethod === 'credit_card' && ! config('services.stripe.secret')) {
            return response()->json(['error' => __('messages.PaymentMethodNotConfigured')], 422);
        }
        if ($paymentMethod === 'paypal' && ! $this->paypal->isConfigured()) {
            return response()->json(['error' => __('messages.PaymentMethodNotConfigured')], 422);
        }
        if ($paymentMethod === 'paystack' && ! $this->paystack->isConfigured()) {
            return response()->json(['error' => __('messages.PaymentMethodNotConfigured')], 422);
        }
        if ($paymentMethod === 'flutterwave' && ! $this->flutterwave->isConfigured()) {
            return response()->json(['error' => __('messages.PaymentMethodNotConfigured')], 422);
        }
        if ($paymentMethod === 'razorpay' && ! $this->razorpay->isConfigured()) {
            return response()->json(['error' => __('messages.PaymentMethodNotConfigured')], 422);
        }
        if ($paymentMethod === 'wallet' && ! $this->wallets->enabled()) {
            return response()->json(['error' => __('messages.PaymentMethodNotConfigured')], 422);
        }
        // Admin on/off for cash-on-delivery and mobile money (default on).
        $storeSetting = \App\Models\StoreSetting::first();
        if ($paymentMethod === 'cod' && $storeSetting && $storeSetting->payment_cod_enabled === false) {
            return response()->json(['error' => __('messages.PaymentMethodNotConfigured')], 422);
        }
        if ($paymentMethod === 'mobile_money' && $storeSetting && $storeSetting->payment_mobile_money_enabled === false) {
            return response()->json(['error' => __('messages.PaymentMethodNotConfigured')], 422);
        }

        // ---- (5a) Customer information: gather + validate completeness -------
        $client = $user->client_id ? Client::find($user->client_id) : null;
        if (! $client) {
            return response()->json(['error' => __('messages.CustomerProfileMissing')], 422);
        }

        $customer = [
            'name' => trim((string) ($data['customer_name'] ?? $client->name)),
            'email' => trim((string) $client->email),
            'phone' => trim((string) ($data['customer_phone'] ?? $client->phone)),
            'address' => trim((string) ($data['shipping_address'] ?? $client->adresse)),
            'city' => trim((string) ($data['shipping_city'] ?? $client->city)),
            'state' => trim((string) ($data['shipping_state'] ?? $client->state)),
            'zip' => trim((string) ($data['shipping_zip'] ?? $client->zip)),
            'country' => trim((string) ($data['shipping_country'] ?? $client->country)),
        ];

        $missing = [];
        foreach (['name', 'email', 'phone', 'address', 'country'] as $field) {
            if ($customer[$field] === '') {
                $missing[] = $field;
            }
        }
        if (! filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
            $missing[] = 'email';
        }
        if ($missing) {
            return response()->json([
                'error' => __('messages.CustomerInfoIncomplete'),
                'missing' => array_values(array_unique($missing)),
            ], 422);
        }

        // ---- Server-side recomputation (5c, 5d, 5e) -------------------------
        try {
            $warehouseIds = $this->checkout->activeWarehouseIds();
            $breakdown = $this->checkout->calculate(
                $data['items'], $warehouseIds, $customer['country'], $customer['state'], $data['shipping_method_id'] ?? null,
                $data['coupon_code'] ?? null, $user->client_id
            );
            // The order is booked under one warehouse: the requested one when
            // it is enabled for the store, else the enabled warehouse holding
            // the most stock for this cart.
            $warehouseId = $this->checkout->selectFulfilmentWarehouseId($breakdown['items'], $data['warehouse_id'] ?? null);

            // Vehicle Fitment: an order may not contain parts that don't fit
            // the customer's selected vehicle.
            $this->assertItemsFitVehicle($breakdown['items']);
        } catch (CheckoutException $e) {
            return $e->toResponse();
        }

        $total = $breakdown['total'];

        // ---- Wallet balance pre-check (fast fail before creating an order) ---
        if ($paymentMethod === 'wallet') {
            $balance = $this->wallets->balanceFor((int) $client->id);
            if (! $this->wallets->allowsNegative() && $balance < $total) {
                return response()->json([
                    'error' => __('messages.WalletInsufficientBalance'),
                    'balance' => $balance,
                    'required' => $total,
                ], 422);
            }
        }

        // ---- (6) Payment verification --------------------------------------
        $piId = $data['stripe_payment_intent_id'] ?? null;
        if ($paymentMethod === 'credit_card') {
            if (! $piId) {
                return response()->json(['error' => __('messages.PaymentConfirmationRequired')], 422);
            }

            // Prevent duplicate payments: an intent may back at most one order.
            if (OnlineOrder::where('stripe_payment_intent_id', $piId)->exists()) {
                return response()->json(['error' => __('messages.PaymentAlreadyUsed')], 409);
            }

            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            try {
                $intent = \Stripe\PaymentIntent::retrieve($piId);
            } catch (\Exception $e) {
                return response()->json(['error' => __('messages.PaymentVerifyFailed')], 422);
            }

            if ($intent->status !== 'succeeded') {
                // Failed/incomplete payment: no order is created; cart stays intact client-side.
                return response()->json(['error' => __('messages.PaymentNotCompleted')], 422);
            }

            // Charged amount must equal the server-computed total (anti-tamper).
            $expectedCents = (int) round($total * 100);
            if ((int) $intent->amount !== $expectedCents) {
                Log::warning("Checkout amount mismatch: intent {$intent->amount} vs expected {$expectedCents}");

                return response()->json(['error' => __('messages.PaymentAmountMismatch')], 422);
            }
        }

        $paymentStatus = in_array($paymentMethod, ['credit_card', 'wallet'], true) ? 'paid' : 'pending';

        // ---- (5g) Fraud heuristics -----------------------------------------
        [$isFlagged, $flagReason] = $this->fraudFlags($user, $breakdown, $customer);

        // Persist the address back to the customer profile for next time.
        $client->fill([
            'name' => $customer['name'] ?: $client->name,
            'phone' => $customer['phone'] ?: $client->phone,
            'adresse' => $customer['address'] ?: $client->adresse,
            'city' => $customer['city'] ?: $client->city,
            'state' => $customer['state'] ?: $client->state,
            'zip' => $customer['zip'] ?: $client->zip,
            'country' => $customer['country'] ?: $client->country,
        ])->save();

        try {
            $order = DB::transaction(function () use ($breakdown, $warehouseId, $warehouseIds, $client, $paymentMethod, $paymentStatus, $piId, $customer, $isFlagged, $flagReason) {
                // ---- (5f) Stock re-check under row locks (prevent overselling) ----
                // Combined stock across all enabled warehouses, matching what
                // the storefront displays.
                $allowOverselling = (bool) (StoreSetting::query()->value('allow_overselling') ?? false);
                if (! $allowOverselling) {
                    $shortages = $this->lockAndCheckStock($breakdown['items'], $warehouseIds);
                    if ($shortages) {
                        throw new CheckoutException(__('messages.InsufficientStock'), 409, ['items' => $shortages]);
                    }
                }

                $order = OnlineOrder::create([
                    'status' => 'pending',
                    'has_preorder_items' => $breakdown['has_preorder_items'],
                    'client_id' => $client->id,
                    'warehouse_id' => $warehouseId,
                    'subtotal' => $breakdown['subtotal'],
                    'discount' => $breakdown['discount'] ?? 0,
                    'coupon_code' => $breakdown['coupon']->code ?? null,
                    'tax' => $breakdown['tax'],
                    'tax_rate' => $breakdown['tax_rate'],
                    'shipping_cost' => $breakdown['shipping_cost'],
                    'total' => $breakdown['total'],
                    'shipping_method_id' => $breakdown['shipping_method']->id ?? null,
                    'shipping_method_name' => $breakdown['shipping_method']->name ?? null,
                    'customer_name' => $customer['name'],
                    'customer_email' => $customer['email'],
                    'customer_phone' => $customer['phone'],
                    'shipping_address' => $customer['address'],
                    'shipping_city' => $customer['city'],
                    'shipping_state' => $customer['state'],
                    'shipping_zip' => $customer['zip'],
                    'shipping_country' => $customer['country'],
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'stripe_payment_intent_id' => $piId,
                    'is_flagged' => $isFlagged,
                    'flag_reason' => $flagReason,
                ]);

                $rows = array_map(function ($i) {
                    return [
                        'product_id' => $i['product_id'],
                        'product_variant_id' => $i['product_variant_id'],
                        'qty' => $i['qty'],
                        'price' => $i['price'],
                        'line_total' => $i['line_total'],
                        'TaxNet' => $i['TaxNet'],
                        'discount' => $i['discount'],
                        'discount_method' => $i['discount_method'],
                        'tax_method' => $i['tax_method'],
                        'is_preorder' => $i['is_preorder'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $breakdown['items']);

                $order->items()->createMany($rows);

                // Record the coupon redemption + bump usage (inside the tx).
                if (! empty($breakdown['coupon']) && ($breakdown['discount'] ?? 0) > 0) {
                    $coupon = $breakdown['coupon'];
                    \App\Models\StoreCouponRedemption::create([
                        'coupon_id' => $coupon->id,
                        'client_id' => $client->id,
                        'online_order_id' => $order->id,
                        'discount' => $breakdown['discount'],
                    ]);
                    \App\Models\StoreCoupon::whereKey($coupon->id)->increment('used_count');
                }

                // ---- Wallet payment: debit the balance inside the same tx -----
                if ($paymentMethod === 'wallet') {
                    $wallet = $this->wallets->walletFor((int) $client->id);
                    $this->wallets->debit($wallet, (float) $breakdown['total'], 'checkout', [
                        'reference_type' => OnlineOrder::class,
                        'reference_id' => $order->id,
                        'note' => __('messages.WalletCheckoutNote', ['ref' => $order->ref]),
                    ]);
                }

                return $order;
            });
        } catch (CheckoutException $e) {
            return $e->toResponse();
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        // ---- PayPal: same flow as the SaaS gateway — the order row exists as
        // payment-pending, a PayPal order (intent CAPTURE) is created for the
        // SERVER-computed total, and the customer is redirected to approve.
        // Capture happens on the return URL; emails are deferred until paid.
        $approveUrl = null;
        if ($paymentMethod === 'paypal') {
            try {
                $pp = $this->paypal->createOrder(
                    (float) $order->total,
                    $this->storeCurrencyCode(),
                    __('messages.OnlineOrder').' '.$order->ref,
                    ['online_order_id' => $order->id, 'client_id' => $client->id],
                    // PayPal appends ?token={paypal_order_id} to both URLs.
                    route('store.paypal.return'),
                    route('store.paypal.cancel')
                );
            } catch (\Throwable $e) {
                Log::error('PayPal checkout failed for order '.$order->id.': '.$e->getMessage());
                $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);

                return response()->json(['error' => __('messages.PaymentSetupFailed')], 502);
            }

            $order->update(['paypal_order_id' => $pp['id']]);
            $approveUrl = $pp['url'];
        }

        // ---- Paystack: same flow as the SaaS gateway — initialize a hosted
        // transaction for the SERVER-computed total with a server-generated
        // reference, redirect to authorize, then VERIFY on the callback.
        if ($paymentMethod === 'paystack') {
            try {
                $ps = $this->paystack->initializeTransaction(
                    (float) $order->total,
                    $this->storeCurrencyCode(),
                    $customer['email'],
                    ['online_order_id' => $order->id, 'client_id' => $client->id],
                    route('store.paystack.return')
                );
            } catch (\Throwable $e) {
                Log::error('Paystack checkout failed for order '.$order->id.': '.$e->getMessage());
                $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);

                return response()->json(['error' => __('messages.PaymentSetupFailed')], 502);
            }

            $order->update(['paystack_reference' => $ps['reference']]);
            $approveUrl = $ps['url'];
        }

        // ---- Flutterwave: same flow as the SaaS gateway — v3 Standard
        // hosted payment with a server-generated tx_ref, redirect to the
        // hosted link, then VERIFY by tx_ref on the redirect back.
        if ($paymentMethod === 'flutterwave') {
            try {
                $fw = $this->flutterwave->initializePayment(
                    (float) $order->total,
                    $this->storeCurrencyCode(),
                    $customer['email'],
                    $customer['name'],
                    __('messages.OnlineOrder').' '.$order->ref,
                    ['online_order_id' => $order->id, 'client_id' => $client->id],
                    route('store.flutterwave.return')
                );
            } catch (\Throwable $e) {
                Log::error('Flutterwave checkout failed for order '.$order->id.': '.$e->getMessage());
                $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);

                return response()->json(['error' => __('messages.PaymentSetupFailed')], 502);
            }

            $order->update(['flutterwave_tx_ref' => $fw['tx_ref']]);
            $approveUrl = $fw['url'];
        }

        // ---- Razorpay: Payment Link for the SERVER-computed total, redirect
        // to the hosted short_url, then VERIFY the link via the API on the
        // callback (same redirect-then-verify pattern as the other gateways).
        if ($paymentMethod === 'razorpay') {
            try {
                $rz = $this->razorpay->createPaymentLink(
                    (float) $order->total,
                    $this->storeCurrencyCode(),
                    __('messages.OnlineOrder').' '.$order->ref,
                    $customer['name'],
                    $customer['email'],
                    ['online_order_id' => $order->id, 'client_id' => $client->id],
                    route('store.razorpay.return')
                );
            } catch (\Throwable $e) {
                Log::error('Razorpay checkout failed for order '.$order->id.': '.$e->getMessage());
                $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);

                return response()->json(['error' => __('messages.PaymentSetupFailed')], 502);
            }

            $order->update(['razorpay_payment_link_id' => $rz['id']]);
            $approveUrl = $rz['url'];
        }

        if (! in_array($paymentMethod, self::REDIRECT_GATEWAYS, true)) {
            $this->sendOrderEmail($order);
            $this->notifyAdminsOfOrder($order);
        }

        return response()->json([
            'id' => $order->id,
            'ref' => $order->ref,
            'status' => $order->status,
            'date' => (string) $order->date,
            'time' => (string) $order->time,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'coupon_code' => $order->coupon_code,
            'tax' => (float) $order->tax,
            'shipping_cost' => (float) $order->shipping_cost,
            'total' => (float) $order->total,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'approve_url' => $approveUrl,
        ], 201);
    }

    /**
     * GET /store/paypal/return?token={paypal_order_id} — PayPal redirects here
     * after the customer approves. Capture the payment (ORDER_ALREADY_CAPTURED
     * is treated as success, matching the SaaS gateway), mark the order paid,
     * send the deferred emails and land on the thank-you page.
     */
    public function paypalReturn(Request $request)
    {
        $token = (string) $request->query('token', '');
        $order = $token !== '' ? OnlineOrder::where('paypal_order_id', $token)->first() : null;

        if (! $order) {
            return redirect()->route('checkout', ['paypal' => 'failed']);
        }

        // Idempotent: a refresh of the return page must not double-process.
        if ($order->payment_status === 'paid') {
            return redirect()->route('store.thankyou', ['paypal' => 'paid']);
        }

        try {
            $capture = $this->paypal->captureOrder($token);
        } catch (\Throwable $e) {
            Log::error('PayPal capture threw for order '.$order->id.': '.$e->getMessage());
            $capture = ['success' => false, 'capture_id' => null];
        }

        if (empty($capture['success'])) {
            return redirect()->route('checkout', ['paypal' => 'failed']);
        }

        $order->update([
            'payment_status' => 'paid',
            'paypal_capture_id' => $capture['capture_id'],
        ]);

        $this->sendOrderEmail($order);
        $this->notifyAdminsOfOrder($order);

        return redirect()->route('store.thankyou', ['paypal' => 'paid']);
    }

    /**
     * GET /store/paypal/cancel?token={paypal_order_id} — the customer backed
     * out on PayPal. Cancel the pending order (stock is never reserved for
     * online orders at this stage) and send them back to the checkout.
     */
    public function paypalCancel(Request $request)
    {
        $token = (string) $request->query('token', '');
        $order = $token !== '' ? OnlineOrder::where('paypal_order_id', $token)->first() : null;

        if ($order && $order->payment_status !== 'paid') {
            $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);
        }

        return redirect()->route('checkout', ['paypal' => 'cancelled']);
    }

    /**
     * GET /store/paystack/return?reference={paystack_reference} — Paystack
     * redirects here after the payment attempt (its callback_url). The
     * redirect alone is NEVER trusted: the transaction is verified via
     * Paystack's Verify endpoint (same rule as the SaaS gateway) before the
     * order is marked paid and the deferred emails go out.
     */
    public function paystackReturn(Request $request)
    {
        $reference = (string) ($request->query('reference') ?: $request->query('trxref', ''));
        $order = $reference !== '' ? OnlineOrder::where('paystack_reference', $reference)->first() : null;

        if (! $order) {
            return redirect()->route('checkout', ['paystack' => 'failed']);
        }

        // Idempotent: a refresh of the callback page must not double-process.
        if ($order->payment_status === 'paid') {
            return redirect()->route('store.thankyou', ['paystack' => 'paid']);
        }

        try {
            $verify = $this->paystack->verifyTransaction($reference);
        } catch (\Throwable $e) {
            Log::error('Paystack verify threw for order '.$order->id.': '.$e->getMessage());
            $verify = ['success' => false, 'status' => null, 'transaction_id' => null];
        }

        if (empty($verify['success'])) {
            // 'abandoned' = the customer backed out on the hosted page.
            $cancelled = ($verify['status'] ?? '') === 'abandoned';
            $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);

            return redirect()->route('checkout', ['paystack' => $cancelled ? 'cancelled' : 'failed']);
        }

        $order->update([
            'payment_status' => 'paid',
            'paystack_transaction_id' => $verify['transaction_id'],
        ]);

        $this->sendOrderEmail($order);
        $this->notifyAdminsOfOrder($order);

        return redirect()->route('store.thankyou', ['paystack' => 'paid']);
    }

    /**
     * GET /store/flutterwave/return?status=…&tx_ref=… — Flutterwave redirects
     * here after the payment attempt. The redirect status is only trusted for
     * an explicit cancel; anything else is verified by tx_ref against the v3
     * API (same rule as the SaaS gateway) before the order is marked paid.
     */
    public function flutterwaveReturn(Request $request)
    {
        $txRef = (string) $request->query('tx_ref', '');
        $order = $txRef !== '' ? OnlineOrder::where('flutterwave_tx_ref', $txRef)->first() : null;

        if (! $order) {
            return redirect()->route('checkout', ['flutterwave' => 'failed']);
        }

        // Idempotent: a refresh of the return page must not double-process.
        if ($order->payment_status === 'paid') {
            return redirect()->route('store.thankyou', ['flutterwave' => 'paid']);
        }

        // The customer backed out on the hosted page.
        if (strtolower((string) $request->query('status', '')) === 'cancelled') {
            $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);

            return redirect()->route('checkout', ['flutterwave' => 'cancelled']);
        }

        try {
            $verify = $this->flutterwave->verifyTransaction($txRef);
        } catch (\Throwable $e) {
            Log::error('Flutterwave verify threw for order '.$order->id.': '.$e->getMessage());
            $verify = ['success' => false, 'transaction_id' => ''];
        }

        if (empty($verify['success'])) {
            $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);

            return redirect()->route('checkout', ['flutterwave' => 'failed']);
        }

        $order->update([
            'payment_status' => 'paid',
            'flutterwave_transaction_id' => $verify['transaction_id'],
        ]);

        $this->sendOrderEmail($order);
        $this->notifyAdminsOfOrder($order);

        return redirect()->route('store.thankyou', ['flutterwave' => 'paid']);
    }

    /**
     * GET /store/razorpay/return?razorpay_payment_link_id=… — Razorpay's
     * callback after the payment attempt. The query params are never trusted:
     * the Payment Link is fetched from the API and must report status "paid"
     * before the order is marked paid and the deferred emails go out.
     */
    public function razorpayReturn(Request $request)
    {
        $linkId = (string) $request->query('razorpay_payment_link_id', '');
        $order = $linkId !== '' ? OnlineOrder::where('razorpay_payment_link_id', $linkId)->first() : null;

        if (! $order) {
            return redirect()->route('checkout', ['razorpay' => 'failed']);
        }

        // Idempotent: a refresh of the callback page must not double-process.
        if ($order->payment_status === 'paid') {
            return redirect()->route('store.thankyou', ['razorpay' => 'paid']);
        }

        try {
            $verify = $this->razorpay->verifyPaymentLink($linkId);
        } catch (\Throwable $e) {
            Log::error('Razorpay verify threw for order '.$order->id.': '.$e->getMessage());
            $verify = ['success' => false, 'status' => null, 'payment_id' => ''];
        }

        if (empty($verify['success'])) {
            // 'cancelled' = the link was cancelled; anything else non-paid is a failure.
            $cancelled = ($verify['status'] ?? '') === 'cancelled';
            $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);

            return redirect()->route('checkout', ['razorpay' => $cancelled ? 'cancelled' : 'failed']);
        }

        $order->update([
            'payment_status' => 'paid',
            'razorpay_payment_id' => $verify['payment_id'],
        ]);

        $this->sendOrderEmail($order);
        $this->notifyAdminsOfOrder($order);

        return redirect()->route('store.thankyou', ['razorpay' => 'paid']);
    }

    /**
     * ISO currency code for gateway charges — same normalization as the
     * Stripe path (store currency_code may hold a symbol; fall back to USD).
     */
    private function storeCurrencyCode(): string
    {
        $currency = strtolower((string) (StoreSetting::query()->value('currency_code') ?: 'usd'));

        return strtoupper(substr(preg_replace('/[^a-z]/', '', $currency) ?: 'usd', 0, 3));
    }

    /**
     * GET /account/orders/{id}/invoice — customer downloads their own invoice.
     */
    public function invoice(Request $request, $id)
    {
        $user = Auth::guard('store')->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        // A store account with no linked client must never reach any order.
        abort_unless($user->client_id, 403);

        $order = OnlineOrder::where('id', $id)
            ->where('client_id', $user->client_id)
            ->first();

        if (! $order) {
            abort(404);
        }

        try {
            return $this->invoices->pdf($order)->download('invoice-'.$order->ref.'.pdf');
        } catch (CheckoutException $e) {
            return $e->toResponse();
        }
    }

    /**
     * Vehicle Fitment purchase guard: when the feature is on and the customer
     * has a vehicle selected, every line must fit it (universal parts always
     * pass). Throws a CheckoutException listing the offending items.
     */
    private function assertItemsFitVehicle(array $items): void
    {
        $fitment = app(\App\Services\FitmentService::class);
        if (! $fitment->enabled() || ! ($vehicle = $fitment->currentVehicle())) {
            return;
        }

        $bad = $fitment->incompatibleItems($items, $vehicle);
        if ($bad) {
            throw new CheckoutException(__('messages.VehicleFitmentMismatch'), 422, [
                'items' => $bad,
                'vehicle_label' => (string) ($vehicle['label'] ?? ''),
            ]);
        }
    }

    /**
     * Lock stock rows and return any lines that cannot be fulfilled.
     * Availability is the combined quantity across the store's enabled
     * warehouses. Pre-order lines are exempt. Returns [] when everything
     * is in stock.
     *
     * @param  int[]  $warehouseIds
     */
    private function lockAndCheckStock(array $items, array $warehouseIds): array
    {
        $shortages = [];

        foreach ($items as $i) {
            if (! empty($i['is_preorder'])) {
                continue;
            }

            $query = product_warehouse::whereIn('warehouse_id', $warehouseIds)
                ->where('product_id', $i['product_id'])
                ->whereNull('deleted_at');

            if (! empty($i['product_variant_id'])) {
                $query->where('product_variant_id', $i['product_variant_id']);
            } else {
                $query->whereNull('product_variant_id');
            }

            $available = (float) $query->lockForUpdate()->get()->sum('qte');

            if ($available < $i['qty']) {
                $shortages[] = [
                    'product_id' => $i['product_id'],
                    'product_variant_id' => $i['product_variant_id'],
                    'name' => $i['product_name'] ?? null,
                    'required' => $i['qty'],
                    'requested' => $i['qty'],
                    'available' => $available,
                ];
            }
        }

        return $shortages;
    }

    /**
     * Lightweight fraud heuristics. These never block an order; they flag it
     * for admin review. Returns [bool $flagged, ?string $reason].
     */
    private function fraudFlags($user, array $breakdown, array $customer): array
    {
        $reasons = [];

        // Velocity: several orders from this customer in a short window.
        $recentOrders = OnlineOrder::where('client_id', $user->client_id)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();
        if ($recentOrders >= 3) {
            $reasons[] = 'rapid_orders';
        }

        // Bulk quantity.
        $totalQty = array_sum(array_map(fn ($i) => (float) $i['qty'], $breakdown['items']));
        if ($totalQty > 100) {
            $reasons[] = 'high_quantity';
        }

        // Brand-new account placing a high-value order.
        $accountAgeMin = $user->created_at ? $user->created_at->diffInMinutes(now()) : null;
        if ($accountAgeMin !== null && $accountAgeMin < 30 && $breakdown['total'] >= 1000) {
            $reasons[] = 'new_account_high_value';
        }

        return [! empty($reasons), $reasons ? implode(',', $reasons) : null];
    }

    /**
     * Best-effort customer order-confirmation email, with the invoice PDF
     * attached when it passes validation. Never breaks the order on failure.
     */
    /** Public: also called by the store WebhookController when a gateway
     *  webhook (not the browser return) is what confirms the payment. */
    public function sendOrderEmail(OnlineOrder $order): void
    {
        if (! $order->customer_email) {
            return;
        }

        try {
            $this->Set_config_mail();
            $storeName = StoreSetting::query()->value('store_name');
            $mail = new OnlineOrderPlaced($order, $storeName);

            // Attach the invoice PDF only if it is internally consistent.
            try {
                $invoice = $this->invoices->build($order);
                if ($invoice['valid']) {
                    $pdf = $this->invoices->pdf($order);
                    $mail->attachData($pdf->output(), 'invoice-'.$order->ref.'.pdf', [
                        'mime' => 'application/pdf',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Invoice attach skipped for order '.$order->ref.': '.$e->getMessage());
            }

            Mail::to($order->customer_email)->send($mail);
        } catch (\Throwable $e) {
            Log::warning('Online order confirmation email failed: '.$e->getMessage());
        }
    }

    /**
     * Notify the store owner (email) and admin users (database bell) of a new
     * order. Best-effort: notification failures never break the order.
     */
    /** Public: also called by the store WebhookController (see sendOrderEmail). */
    public function notifyAdminsOfOrder(OnlineOrder $order): void
    {
        $storeName = StoreSetting::query()->value('store_name');

        // 1) Email the store owner once.
        try {
            $this->Set_config_mail();
            $ownerEmail = StoreSetting::query()->value('contact_email')
                ?: optional(Setting::whereNull('deleted_at')->first())->email;

            if ($ownerEmail) {
                Mail::to($ownerEmail)->send(new AdminNewOrderMail($order, $storeName));
            }
        } catch (\Throwable $e) {
            Log::warning('Admin new-order email failed: '.$e->getMessage());
        }

        // 2) Record a database notification for admin users (role_id = 1).
        try {
            $admins = User::where('role_id', 1)->whereNull('deleted_at')->get();
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new NewOnlineOrderNotification($order));
            }
        } catch (\Throwable $e) {
            Log::warning('Admin new-order notification failed: '.$e->getMessage());
        }
    }
}
