<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use App\Services\FlutterwaveService;
use App\Services\PayPalService;
use App\Services\PaystackService;
use App\Services\RazorpayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Online-store payment gateway webhooks — the backstop the SaaS version has:
 * when the customer pays but the browser redirect never lands (tab closed,
 * network drop), the gateway's server-to-server event still marks the order
 * paid. Also records refunds/chargebacks issued from the gateway dashboard.
 *
 * Signature verification lives in the gateway services (PayPal
 * verify-webhook-signature API / Paystack HMAC-SHA512); an invalid signature
 * is rejected with 400 and nothing is touched. Processing is idempotent —
 * the browser-return handlers and these webhooks can arrive in any order.
 */
class WebhookController extends Controller
{
    public function paypal(Request $request, PayPalService $paypal)
    {
        $result = $paypal->verifyWebhook(
            $request->getContent(),
            (string) $request->header('PAYPAL-TRANSMISSION-SIG', '')
        );

        return $this->process('paypal', $result);
    }

    public function paystack(Request $request, PaystackService $paystack)
    {
        $result = $paystack->verifyWebhook(
            $request->getContent(),
            (string) $request->header('x-paystack-signature', '')
        );

        return $this->process('paystack', $result);
    }

    public function flutterwave(Request $request, FlutterwaveService $flutterwave)
    {
        $result = $flutterwave->verifyWebhook(
            $request->getContent(),
            (string) $request->header('verif-hash', '')
        );

        return $this->process('flutterwave', $result);
    }

    public function razorpay(Request $request, RazorpayService $razorpay)
    {
        $result = $razorpay->verifyWebhook(
            $request->getContent(),
            (string) $request->header('X-Razorpay-Signature', '')
        );

        return $this->process('razorpay', $result);
    }

    private function process(string $gateway, array $result)
    {
        if (empty($result['valid'])) {
            return response('Invalid signature.', 400);
        }

        Log::info("Store webhook received: {$gateway} / {$result['event_type']}", [
            'online_order_id' => $result['online_order_id'] ?? null,
            'status' => $result['status'],
        ]);

        if ($result['status'] === 'paid') {
            $this->handlePaid($gateway, $result);
        } elseif ($result['status'] === 'refunded') {
            $this->handleRefunded($gateway, $result);
        } elseif ($result['status'] === 'failed') {
            $this->handleFailed($gateway, $result);
        }

        // Always 200 for verified events, even unhandled types — otherwise the
        // gateway keeps retrying deliveries forever.
        return response('OK', 200);
    }

    private function handlePaid(string $gateway, array $result): void
    {
        $order = $this->findOrder($gateway, $result);
        if (! $order) {
            Log::warning("Store webhook ({$gateway}): order not found for paid event.", $result);

            return;
        }

        // Idempotent with the browser-return capture/verify path.
        if ($order->payment_status === 'paid') {
            return;
        }

        $order->update([
            'payment_status' => 'paid',
            // If the order was auto-cancelled (e.g. verify failed transiently)
            // but the money actually moved, reopen it for fulfilment.
            'status' => $order->status === 'cancelled' ? 'pending' : $order->status,
        ] + match ($gateway) {
            'paypal' => ['paypal_capture_id' => $result['transaction_id'] ?: $order->paypal_capture_id],
            'paystack' => ['paystack_transaction_id' => $result['transaction_id'] ?: $order->paystack_transaction_id],
            'flutterwave' => ['flutterwave_transaction_id' => $result['transaction_id'] ?: $order->flutterwave_transaction_id],
            'razorpay' => ['razorpay_payment_id' => $result['transaction_id'] ?: $order->razorpay_payment_id],
            default => [],
        });

        // Deferred order emails (skipped at creation for redirect gateways).
        $checkout = app(CheckoutController::class);
        $checkout->sendOrderEmail($order);
        $checkout->notifyAdminsOfOrder($order);
    }

    private function handleRefunded(string $gateway, array $result): void
    {
        $order = $this->findOrder($gateway, $result);
        if (! $order) {
            Log::warning("Store webhook ({$gateway}): order not found for refund event.", $result);

            return;
        }

        if ($order->payment_status !== 'refunded') {
            $order->update(['payment_status' => 'refunded']);
            Log::info("Store webhook ({$gateway}): order {$order->id} marked refunded.");
        }
    }

    private function handleFailed(string $gateway, array $result): void
    {
        $order = $this->findOrder($gateway, $result);

        // Never downgrade a paid order on a late/failed retry event.
        if ($order && $order->payment_status === 'pending') {
            $order->update(['payment_status' => 'failed']);
        }
    }

    /**
     * Resolve the order: by the online_order_id carried in the gateway
     * metadata first, falling back to the stored gateway ids (capture id /
     * reference / transaction id) for events that carry no metadata.
     */
    private function findOrder(string $gateway, array $result): ?OnlineOrder
    {
        if (! empty($result['online_order_id'])) {
            $order = OnlineOrder::find($result['online_order_id']);
            if ($order) {
                return $order;
            }
        }

        $txn = (string) ($result['transaction_id'] ?? '');
        $ref = (string) ($result['gateway_payment_id'] ?? '');

        // No identifiers at all: an empty where() would match ANY order.
        if ($txn === '' && $ref === '') {
            return null;
        }

        if ($gateway === 'paypal') {
            return OnlineOrder::where(function ($q) use ($txn, $ref) {
                $q->when($txn !== '', fn ($w) => $w->orWhere('paypal_capture_id', $txn)->orWhere('paypal_order_id', $txn));
                $q->when($ref !== '', fn ($w) => $w->orWhere('paypal_capture_id', $ref)->orWhere('paypal_order_id', $ref));
            })->first();
        }

        if ($gateway === 'flutterwave') {
            return OnlineOrder::where(function ($q) use ($txn, $ref) {
                $q->when($txn !== '', fn ($w) => $w->orWhere('flutterwave_transaction_id', $txn)->orWhere('flutterwave_tx_ref', $txn));
                $q->when($ref !== '', fn ($w) => $w->orWhere('flutterwave_tx_ref', $ref)->orWhere('flutterwave_transaction_id', $ref));
            })->first();
        }

        if ($gateway === 'razorpay') {
            return OnlineOrder::where(function ($q) use ($txn, $ref) {
                $q->when($txn !== '', fn ($w) => $w->orWhere('razorpay_payment_id', $txn)->orWhere('razorpay_payment_link_id', $txn));
                $q->when($ref !== '', fn ($w) => $w->orWhere('razorpay_payment_link_id', $ref)->orWhere('razorpay_payment_id', $ref));
            })->first();
        }

        return OnlineOrder::where(function ($q) use ($txn, $ref) {
            $q->when($txn !== '', fn ($w) => $w->orWhere('paystack_transaction_id', $txn)->orWhere('paystack_reference', $txn));
            $q->when($ref !== '', fn ($w) => $w->orWhere('paystack_reference', $ref)->orWhere('paystack_transaction_id', $ref));
        })->first();
    }
}
