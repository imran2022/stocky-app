<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Setting;
use App\Services\Custom\PublicInvoiceLinkService;
use Illuminate\Http\Request;

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
        $sale = $this->findByToken($token, ['details.product', 'client']);
        $setting = Setting::where('deleted_at', null)->first();

        $subtotal = $sale->details->sum('total');

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
            ],
            'client' => [
                'name' => $sale->client->name ?? '',
                'phone' => $sale->client->phone ?? '',
                'email' => $sale->client->email ?? '',
                'address' => $sale->client->adresse ?? '',
            ],
            'items' => $sale->details->map(fn ($d) => [
                'name' => $d->product->name ?? '',
                'code' => $d->product->code ?? '',
                'quantity' => (float) $d->quantity,
                'price' => (float) $d->price,
                'discount' => (float) ($d->discount ?? 0),
                'total' => (float) $d->total,
            ]),
            'totals' => [
                'subtotal' => (float) $subtotal,
                'discount' => (float) ($sale->discount ?? 0),
                'tax' => (float) ($sale->TaxNet ?? 0),
                'shipping' => (float) ($sale->shipping ?? 0),
                'grand_total' => (float) $sale->GrandTotal,
                'paid' => (float) $sale->paid_amount,
                'due' => (float) ($sale->GrandTotal - $sale->paid_amount),
            ],
            'pdf_url' => $this->publicPdfUrl($token),
        ]);
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
