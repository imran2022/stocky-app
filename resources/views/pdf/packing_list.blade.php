{{--
    Packing List PDF — restyled (Build L5, 2026-09-19) to match the visual
    language of the Modern Sale Invoice (sale_pdf_modern.blade.php): same
    color palette, header layout, product-table look, and uppercase
    micro-labels. The Box column is dynamic like the invoice's — shown
    only when at least one line item actually has a box quantity.
    $sale/$details/$totalQty/$totalBoxes are unchanged; $setting (the
    company row) was added by SalesController::Sale_Packing_List() so the
    header can show the company name/logo, same as the invoice does.

    Note: this comment must stay a Blade comment block (stripped at
    compile time), not a raw HTML comment — a raw HTML comment with
    non-ASCII characters (like the em-dashes above) ends up in the
    rendered output before the charset meta tag, which throws off
    DomPDF's encoding auto-detection and garbles every em-dash later in
    the document (found and fixed during Build L5 verification).
--}}
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    {{-- Same technique the Modern Sale Invoice uses for a PDF download:
         @page margin at 0 and the visual margin done with body padding
         instead. This is the pairing that's already proven reliable in
         this app's PDF pipeline for downloads (Sale_Packing_List always
         downloads, so it always uses this branch) — using a plain @page
         margin on its own, as an earlier version of this file did, is
         what produced the content sitting flush against the page edges
         that was reported after Build L5. --}}
    @page { margin: 0; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 9pt;
        color: #334155;
        line-height: 1.25;
        background-color: #ffffff;
        padding: 25px 25px 35px 25px;
    }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .label { font-size: 7.5pt; font-weight: bold; color: #94a3b8; text-transform: uppercase; margin-bottom: 1px; display: block; }

    .product-table thead tr { background-color: #f8fafc; }
    .product-table th { padding: 6px 10px; color: #475569; font-size: 8.5pt; font-weight: bold; text-align: left; border-bottom: 1px solid #e2e8f0; }
    .product-table td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; line-height: 1.2; }
    .product-table tbody tr { page-break-inside: avoid; break-inside: avoid; }
    .col-normal { font-weight: normal !important; }
    .col-bold { font-weight: bold !important; }
    .product-name { color: #1e293b; }

    .calc-container { border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; page-break-inside: avoid; break-inside: avoid; }
    .calc-container td { padding: 6px 12px; border-bottom: 1px solid #f1f5f9; }
</style>
</head>
<body>

@php
    $hasBoxQty = $totalBoxes !== null && (bool) ($setting['enable_box_qty'] ?? true);
    $descWidth = $hasBoxQty ? 55 : 66;
    $codeWidth = $hasBoxQty ? 12 : 16;

    $logoSrc = null;
    if (!empty($setting['logo'])) {
        $logoPath = public_path('images/'.$setting['logo']);
        if (file_exists($logoPath)) {
            $logoSrc = 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath));
        }
    }
@endphp

<table style="margin-bottom: 15px;">
    <tr>
        <td style="width: 60%; vertical-align: top;">
            <table style="width: auto;">
                <tr>
                    <td style="width: 70px; vertical-align: top;">
                        @if($logoSrc) <img src="{{ $logoSrc }}" style="max-height: 50px;"> @endif
                    </td>
                    <td style="vertical-align: top; padding-left: 12px;">
                        <div style="font-size: 11pt; font-weight: bold; color: #0f172a;">{{ $setting['CompanyName'] ?? '' }}</div>
                        <div style="font-size: 8pt; color: #64748b;">{{ $setting['CompanyAdress'] ?? '' }}</div>
                        @if(!empty($setting['vat_number']))
                        <div style="font-size: 8pt; color: #64748b;">VAT/BIN: {{ $setting['vat_number'] }}</div>
                        @endif
                        <div style="font-size: 8pt; color: #64748b;">Phone: {{ $setting['CompanyPhone'] ?? '' }}</div>
                        <div style="font-size: 8pt; color: #64748b;">Mail: {{ $setting['email'] ?? '' }}</div>
                        @if(!empty($setting['website']))
                        <div style="font-size: 8pt; color: #64748b;">Website: {{ $setting['website'] }}</div>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 40%; text-align: right; vertical-align: top;">
            <div style="font-size: 20pt; font-weight: 800; color: #0f172a; line-height: 1;">PACKING LIST</div>
            <div style="font-size: 10pt; font-weight: bold; color: #475569; margin-top: 2px;">Ref: {{ $sale['Ref'] }}</div>
        </td>
    </tr>
</table>

<div style="height: 1px; background: #f1f5f9; margin-bottom: 12px;"></div>

<table style="margin-bottom: 15px;">
    <tr>
        <td style="width: 50%; vertical-align: top;">
            <span class="label">Customer</span>
            <div style="font-size: 9.5pt; font-weight: bold; color: #1e293b;">{{ $sale['client_name'] }}</div>
        </td>
        <td style="width: 50%; vertical-align: top; text-align: right;">
            <span class="label">Date</span>
            <div style="font-size: 9.5pt; font-weight: bold; color: #1e293b;">{{ $sale['date'] }}</div>
        </td>
    </tr>
</table>

<table class="product-table" style="margin-bottom: 15px;">
    <thead>
        <tr>
            <th style="width: 6%; text-align: center;">#</th>
            <th style="width: {{ $descWidth }}%;">Product</th>
            <th style="width: {{ $codeWidth }}%;">Code / SKU</th>
            @if($hasBoxQty) <th style="width: 8%; text-align: center;">Box</th> @endif
            <th style="width: 12%; text-align: center;">Qty</th>
        </tr>
    </thead>
    <tbody>
        @foreach($details as $index => $detail)
        <tr>
            <td class="col-normal" style="text-align: center; color: #94a3b8; white-space: nowrap; font-size: 9pt;">
                {{ sprintf('%02d', $index + 1) }}
            </td>
            <td class="col-normal">
                <div class="product-name">{{ $detail['name'] }}</div>
            </td>
            <td class="col-normal" style="color: #64748b; font-size: 8pt;">{{ $detail['code'] }}</td>
            @if($hasBoxQty) <td class="col-normal" style="text-align: center; color: #1e293b;">{{ $detail['box_qty'] ?? '—' }}</td> @endif
            <td class="col-bold" style="text-align: center; color: #0f172a;">{{ $detail['quantity'] }} {{ $detail['unitSale'] ?? '' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<table>
    <tr>
        <td style="width: 60%;"></td>
        <td style="width: 40%;">
            <div class="calc-container">
                <table style="width: 100%;">
                    <tr style="background: #f8fafc;">
                        <td style="padding: 8px 12px; font-size: 9.5pt; font-weight: 900; color: #0f172a;">Total Items</td>
                        <td style="padding: 8px 12px; font-size: 10.5pt; font-weight: 900; color: #0f172a; text-align: right;">{{ $totalQty }}</td>
                    </tr>
                    @if($hasBoxQty)
                    <tr>
                        <td style="font-size: 8.5pt; color: #64748b;">Total Boxes</td>
                        <td style="font-weight: bold; text-align: right; color: #1e293b;">{{ $totalBoxes !== null ? $totalBoxes : '—' }}</td>
                    </tr>
                    @endif
                </table>
            </div>
        </td>
    </tr>
</table>

<div style="margin-top: 15px; page-break-inside: avoid; break-inside: avoid;">
    <div style="height: 1px; background: #e2e8f0; width: 100%; margin-bottom: 6px;"></div>
    <div style="text-align: center;">
        <div style="font-size: 7.5pt; color: #94a3b8;">This is a computer generated packing list — no prices shown.</div>
        <div style="font-size: 7.5pt; color: #94a3b8; margin-top: 1px;">Generated on: {{ date('M d, Y h:i A') }}</div>
    </div>
</div>

</body>
</html>
