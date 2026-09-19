<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PdfTemplate;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\ServiceJob;
use App\Models\Setting;
use App\Models\Unit;
use App\Services\BatchService;
use App\Services\Custom\PublicInvoiceLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public Invoice URL.
 *
 * `link()` and `regenerate()` are authenticated (staff generating/copying
 * the shareable link from the Sale Detail page) and now point at the
 * branded HTML invoice page (`/invoice/{token}` in the SPA), not
 * straight at the PDF — the page itself has the "Download PDF" button.
 * `show()` and `pdf()` are the actual public endpoints — no auth,
 * reachable by anyone with the link, looked up by an unguessable token
 * rather than the sale's own id.
 *
 * `pdf()` deliberately does not rebuild the invoice PDF itself — it
 * resolves the token to a real sale id and delegates straight to
 * SalesController::Sale_PDF(), the same method every authenticated PDF
 * download already uses, so the downloaded PDF can never drift out of
 * sync with what staff see there.
 *
 * `show()` is a separate, simpler data shape purpose-built for the new
 * page's own modern layout — it deliberately does NOT reuse Sale_PDF's
 * data-building (that method mixes PDF-specific concerns — Arabic glyph
 * shaping, per-line unit/pack resolution, RTL layout flags — into one
 * long method with no separable "just the data" entry point, and
 * refactoring it was judged riskier than the modest duplication of a few
 * field reads here). One acknowledged consequence: amounts here are in
 * the sale's own stored currency, not run through the multi-currency
 * conversion Sale_PDF applies for a viewer-selected display currency —
 * fine for "here is the invoice", and the authoritative PDF (same
 * download button) still reflects the full conversion if that ever
 * matters for a given sale.
 *
 * Build L1 (2026-09-19) — brought show()'s field set up to parity with
 * the authenticated Sale Detail page (SaleDetails.vue / SalesController::
 * show()): per-line Box Qty (only when the "enable_box_qty" company
 * setting is on — same flag SaleDetails.vue reads), per-line Discount and
 * Tax, order-level Discount (fixed/percent aware) + Discount from Points +
 * Previous Dues + Net Balance. `clientPreviousDues()` below is a
 * deliberate copy of SalesController's private method of the same name —
 * same reasoning as the class-level note above (that method is `private`,
 * not shared, and duplicating ~15 lines was judged safer than changing
 * SalesController's visibility or extracting a shared service for one
 * caller). The customer's own email is intentionally left out of the
 * `client` block — this page is reached via a link the customer already
 * has, showing their email back to them serves no purpose.
 */
class PublicInvoiceController extends BaseController
{
    public function link(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Sale::class);

        $sale = Sale::where('deleted_at', null)->findOrFail($id);
        $token = PublicInvoiceLinkService::getOrCreateToken($sale);

        return response()->json(['url' => $this->publicPageUrl($token)]);
    }

    public function regenerate(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Sale::class);

        $sale = Sale::where('deleted_at', null)->findOrFail($id);
        $token = PublicInvoiceLinkService::regenerateToken($sale);

        return response()->json(['url' => $this->publicPageUrl($token)]);
    }

    /**
     * No auth. JSON data for the branded HTML invoice page.
     */
    public function show(string $token)
    {
        $sale = $this->findByToken($token, ['details.product.unitSale', 'client']);
        $setting = Setting::where('deleted_at', null)->first();
        $enableBoxQty = (bool) ($setting->enable_box_qty ?? true);
        $enablePaymentTerms = (bool) ($setting->enable_payment_terms ?? true);

        $subtotal = $sale->details->sum('total');

        $discountMethod = $sale->discount_Method ?? '2'; // '1' = percent, '2' = fixed
        $orderDiscountAmount = $discountMethod == '1'
            ? round($subtotal * ((float) $sale->discount / 100), 2)
            : min((float) $sale->discount, $subtotal);

        $saleBatchesByDetail = app(BatchService::class)->batchesForSaleDetails($sale->details);

        $paid = (float) $sale->paid_amount;
        $due = (float) $sale->GrandTotal - $paid;
        $previousDues = $this->clientPreviousDues($sale->client_id, $sale->id);

        // Same Settings → Invoice PDF → Sections switches the Sale PDF and
        // Sale Detail page use, so all three surfaces agree.
        $pdfSettings = PdfTemplate::settingsFor('sale');

        return response()->json([
            'company' => [
                'name' => $setting->CompanyName ?? '',
                'phone' => $setting->CompanyPhone ?? '',
                'email' => $setting->email ?? '',
                'address' => $setting->CompanyAdress ?? '',
                'vat_number' => $setting->vat_number ?? '',
                'website' => $setting->website ?? '',
                'logo_url' => $setting && $setting->logo ? url('/images/'.$setting->logo) : null,
            ],
            'invoice' => [
                'ref' => $sale->Ref,
                'date' => $sale->date,
                'status' => $sale->statut,
                'payment_status' => $sale->payment_statut,
                'warehouse' => optional($sale->warehouse)->name,
            ],
            'client' => [
                'name' => $sale->client->name ?? '',
                'phone' => $sale->client->phone ?? '',
                // No email: the customer already holds this link — showing
                // their own email back to them adds nothing (see class note).
                'address' => $sale->client->adresse ?? '',
                'tax_number' => $sale->client->tax_number ?? '',
            ],
            'enable_box_qty' => $enableBoxQty,
            'items' => $sale->details->map(function ($d) use ($enableBoxQty, $saleBatchesByDetail) {
                $discountNet = $d->discount_method == '2'
                    ? (float) $d->discount
                    : (float) $d->price * (float) $d->discount / 100;
                $taxPrice = (float) $d->TaxNet * (((float) $d->price - $discountNet) / 100);
                $taxNet = $d->tax_method == '1'
                    ? $taxPrice
                    : (float) $d->price - ((float) $d->price - $discountNet - $taxPrice) - $discountNet;

                // Same variant/unit resolution as SalesController::show() —
                // sale_unit_id wins when set, else the product's own unitSale.
                $variant = $d->product_variant_id
                    ? ProductVariant::where('product_id', $d->product_id)->where('id', $d->product_variant_id)->first()
                    : null;

                $unit = $d->sale_unit_id !== null
                    ? Unit::find($d->sale_unit_id)
                    : optional($d->product)->unitSale;

                return [
                    'name' => ($variant ? '['.$variant->name.']' : '').($d->product->name ?? ''),
                    'code' => $variant ? $variant->code : ($d->product->code ?? ''),
                    'quantity' => (float) $d->quantity,
                    'unit' => $unit->ShortName ?? '',
                    'pack_name' => $d->pack_name,
                    'pack_multiplier' => $d->pack_multiplier !== null ? (float) $d->pack_multiplier : 1,
                    'box_qty' => $enableBoxQty && $d->box_qty !== null ? (float) $d->box_qty : null,
                    'price' => (float) $d->price,
                    'discount' => round($discountNet * (float) $d->quantity, 2),
                    'tax' => round($taxNet * (float) $d->quantity, 2),
                    'total' => (float) $d->total,
                    'is_imei' => (bool) ($d->product->is_imei ?? false),
                    'imei_number' => $d->imei_number,
                    'is_batch_tracked' => (bool) ($d->product->is_batch_tracked ?? false),
                    'batches' => $saleBatchesByDetail[(int) $d->id] ?? [],
                ];
            }),
            'totals' => [
                'subtotal' => (float) $subtotal,
                'discount' => round($orderDiscountAmount, 2),
                'discount_method' => (string) $discountMethod,
                'discount_percent' => $discountMethod == '1' ? (float) $sale->discount : null,
                'discount_from_points' => (float) ($sale->discount_from_points ?? 0),
                'tax' => (float) ($sale->TaxNet ?? 0),
                'shipping' => (float) ($sale->shipping ?? 0),
                'grand_total' => (float) $sale->GrandTotal,
                'paid' => $paid,
                'due' => $due,
                'previous_dues' => (float) $previousDues,
                'net_balance' => (float) $previousDues + $due,
                'show_previous_dues' => (bool) $pdfSettings['show_previous_dues'],
                'show_net_balance' => (bool) $pdfSettings['show_net_balance'],
                // Payment Terms & Due Dates (Build M1).
                'due_date' => $enablePaymentTerms ? $sale->due_date : null,
                'is_overdue' => $enablePaymentTerms ? \App\Support\PaymentTerms::isOverdue($sale->due_date, $due) : false,
                'show_due_date' => (bool) ($pdfSettings['show_due_date'] ?? true),
            ],
            'pdf_url' => $this->publicPdfUrl($token),
        ]);
    }

    /**
     * Copied from SalesController's private method of the same name — see
     * the Build L1 class-level note above for why.
     */
    private function clientPreviousDues($clientId, $excludeSaleId = null)
    {
        if (! $clientId) {
            return 0;
        }

        $salesQuery = DB::table('sales')
            ->whereNull('deleted_at')
            ->where('statut', 'completed')
            ->where('client_id', $clientId);

        if ($excludeSaleId) {
            $salesQuery->where('id', '!=', $excludeSaleId);
        }

        $sales_grand = (clone $salesQuery)->sum('GrandTotal');
        $sales_paid = (clone $salesQuery)->sum('paid_amount');

        $return_grand = DB::table('sale_returns')
            ->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->sum('GrandTotal');
        $return_paid = DB::table('sale_returns')
            ->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->sum('paid_amount');

        $opening_balance = optional(Client::find($clientId))->opening_balance ?? 0;

        $service_due = ServiceJob::dueTotalsForClient($clientId)['due'];

        return $opening_balance + ($sales_grand - $sales_paid) + $service_due - ($return_grand - $return_paid);
    }

    /**
     * No auth. `?inline=1` is passed through to Sale_PDF so the browser
     * opens the PDF directly (viewable) rather than forcing a save dialog
     * — the reader can still download/print/save from their own PDF
     * viewer, which is all "downloadable" needs to mean here.
     */
    public function pdf(Request $request, string $token)
    {
        $sale = $this->findByToken($token);

        $request->merge(['inline' => true]);

        return app(SalesController::class)->Sale_PDF($request, $sale->id);
    }

    private function findByToken(string $token, array $with = []): Sale
    {
        return Sale::with($with)
            ->where('public_token', $token)
            ->whereNotNull('public_token')
            ->where('deleted_at', null)
            ->firstOrFail();
    }

    private function publicPageUrl(string $token): string
    {
        // The SPA's own base path (createWebHistory('/next/')) plus the new
        // skipAuth route registered at /invoice/:token.
        return url('/next/invoice/'.$token);
    }

    private function publicPdfUrl(string $token): string
    {
        // routes/api.php is registered under a global 'api' prefix
        // (RouteServiceProvider) — must be included here or this 404s
        // despite the route itself being correct.
        return url('/api/public/invoice/'.$token.'/pdf');
    }
}
