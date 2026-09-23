{{--
    "Modern" Sale Invoice layout — Build L3 (2026-09-19), extended in
    Build L4 (2026-09-19). One of two selectable layouts for the Sales
    Invoice PDF (Settings → Invoice PDF → Sales Invoice → Template).
    Supplied as a ready-made design.
--}}
@php
    $pdfLocale = app()->getLocale();
    $isRtl = $pdfLocale === 'ar';
    $rtlLabelSuffix = $isRtl ? '' : ':';

    $isPdfDownload = request()->is('*pdf*') || request()->is('*download*') || request()->has('pdf');
    $pdfT = \App\Models\PdfTemplate::settingsFor('sale');
@endphp
<!DOCTYPE html>
<html lang="{{ $pdfLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Sale Invoice - {{$sale['Ref']}}</title>
    @php
        $priceFormat = $setting['price_format'] ?? null;
        if (!function_exists('formatPrice')) {
            function formatPrice($number, $decimals = 2, $priceFormat = null) {
                $number = (float) $number;
                if (empty($priceFormat)) return number_format($number, $decimals, '.', ',');
                switch ($priceFormat) {
                    case 'comma_dot': return number_format($number, $decimals, '.', ',');
                    case 'dot_comma': return number_format($number, $decimals, ',', '.');
                    case 'space_comma': return number_format($number, $decimals, ',', ' ');
                    default: return number_format($number, $decimals, '.', ',');
                }
            }
        }
        
        $taxAmount = (float)($sale['TaxNet'] ?? 0);
        $shippingAmount = (float)($sale['shipping'] ?? 0);
        $discountFromPoints = (float)($sale['discount_from_points'] ?? 0);

        $discountMethod = $sale['discount_Method'] ?? '2';
        $discountRaw = (float)($sale['discount'] ?? 0);
        $provisionalSubtotal = $sale['GrandTotal'] - $shippingAmount - $taxAmount + $discountFromPoints;
        $discountAmount = $discountMethod === '1'
            ? round($provisionalSubtotal * ($discountRaw / (100 - $discountRaw)), 2)
            : min($discountRaw, $provisionalSubtotal);
        $subtotal = $provisionalSubtotal + $discountAmount;

        $hasLineDiscount = false;
        $hasLineTax = false;
        $anyLineHasBoxQty = false;
        foreach ($details as $detail) {
            if ((float)($detail['DiscountNet'] ?? 0) > 0) $hasLineDiscount = true;
            if ((float)($detail['taxe'] ?? 0) > 0) $hasLineTax = true;
            if (($detail['box_qty'] ?? null) !== null) $anyLineHasBoxQty = true;
        }
        $hasBoxQty = $anyLineHasBoxQty && (bool) ($setting['enable_box_qty'] ?? true);

        $descWidth = 37;
        if (!$hasLineDiscount) $descWidth += 10;
        if (!$hasLineTax) $descWidth += 10;
        if (!$hasBoxQty) $descWidth += 6;
    @endphp
    <style>
        @page { 
            size: A4; 
            @if($isPdfDownload) margin: 0; @else margin: 0.4in; @endif
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9pt; 
            color: #334155; 
            line-height: 1.25;
            @if($isPdfDownload) padding: 25px 25px 35px 25px; @else padding: 0; @endif
            background-color: #ffffff;
        }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .label { font-size: 7.5pt; font-weight: bold; color: #94a3b8; text-transform: uppercase; margin-bottom: 1px; display: block; }
        .product-table thead tr { background-color: #f8fafc; }
        .product-table th { padding: 5px 10px; color: #475569; font-size: 8.5pt; font-weight: bold; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .product-table td { padding: 6px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; line-height: 1.2; }
        .product-table tbody { page-break-inside: avoid; break-inside: avoid; }
        .col-normal { font-weight: normal !important; }
        .col-bold { font-weight: bold !important; }
        .product-name { color: #1e293b; }
        .status-badge { background: #2563eb; color: #ffffff; padding: 2px 10px; border-radius: 4px; font-size: 7.5pt; font-weight: bold; text-transform: uppercase; }
        .calc-container { border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; page-break-inside: avoid; break-inside: avoid; }
        .calc-container td { padding: 4px 12px; border-bottom: 1px solid #f1f5f9; }
    </style>
</head>
<body>

    <table style="margin-bottom: 15px;">
        <tr>
            <td style="width: 60%; vertical-align: top;">
                @php
                    $logoSrc = null;
                    if (!empty($setting['logo'])) {
                        $logoPath = public_path('images/'.$setting['logo']);
                        if (file_exists($logoPath)) {
                            $logoSrc = 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath));
                        }
                    }
                @endphp
                <table style="width: auto;">
                    <tr>
                        <td style="width: 70px; vertical-align: top;">
                            @if($logoSrc) <img src="{{ $logoSrc }}" style="max-height: 50px;"> @endif
                        </td>
                        <td style="vertical-align: top; padding-left: 12px;">
                            <div style="font-size: 11pt; font-weight: bold; color: #0f172a;">{{$setting['CompanyName']}}</div>
                            <div style="font-size: 8pt; color: #64748b;">{{$setting['CompanyAdress']}}</div>
                            @if(!empty($setting['vat_number']))
                            <div style="font-size: 8pt; color: #64748b;">VAT/BIN: {{$setting['vat_number']}}</div>
                            @endif
                            <div style="font-size: 8pt; color: #64748b;">Phone: {{$setting['CompanyPhone']}}</div>
                            <div style="font-size: 8pt; color: #64748b;">Mail: {{$setting['email']}}</div>
                            @if(!empty($setting['website']))
                            <div style="font-size: 8pt; color: #64748b;">Website: {{$setting['website']}}</div>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 40%; text-align: right; vertical-align: top;">
                <div style="font-size: 20pt; font-weight: 800; color: #0f172a; line-height: 1;">{{ !empty($pdfT['labels']['title']) ? $pdfT['labels']['title'] : 'INVOICE' }}</div>
                <div style="font-size: 10pt; font-weight: bold; color: #475569; margin-top: 2px;">Invoice No: {{$sale['Ref']}}</div>
                <div style="margin-top: 4px;">
                    <span style="color: #10b981; font-weight: bold; font-size: 8.5pt;">Paid: {{$symbol}} {{formatPrice($sale['paid_amount'], 2, $priceFormat)}}</span>
                    <span style="color: #e11d48; font-weight: bold; font-size: 8.5pt; margin-left: 12px;">Due: {{$symbol}} {{formatPrice($sale['due'], 2, $priceFormat)}}</span>
                </div>

                {{-- Delivery Info (Build M6): Warehouse / Tracking Ref / Zone / Courier, only the ones set --}}
                @php
                    $deliveryParts = [];
                    if (!empty($sale['warehouse'])) { $deliveryParts[] = ['Warehouse', $sale['warehouse']]; }
                    if (!empty($sale['tracking_ref'])) { $deliveryParts[] = ['Tracking Ref', $sale['tracking_ref']]; }
                    if (!empty($sale['zone_name'])) { $deliveryParts[] = ['Zone', $sale['zone_name']]; }
                    if (!empty($sale['courier_name'])) { $deliveryParts[] = ['Courier', $sale['courier_name']]; }
                @endphp
                @if(!empty($pdfT['show_delivery_info']) && count($deliveryParts))
                <div style="margin-top: 5px; font-size: 8pt; color: #64748b;">
                    <span class="label" style="display: inline; margin-right: 4px;">Delivery Info</span>
                    @foreach($deliveryParts as $i => $part)
                        @if($i > 0)<span style="color: #cbd5e1;">&nbsp;&nbsp;|&nbsp;&nbsp;</span>@endif
                        <strong style="color: #1e293b;">{{ $part[0] }}:</strong> {{ $part[1] }}
                    @endforeach
                </div>
                @endif
            </td>
        </tr>
    </table>

    <div style="height: 1px; background: #f1f5f9; margin-bottom: 12px;"></div>

    <table style="margin-bottom: 15px;">
        <tr>
            <td style="width: 45%; vertical-align: top; {{ !empty($pdfT['show_customer']) ? '' : 'display:none;' }}">
                <span class="label">Customer</span>
                <div style="font-size: 9.5pt; font-weight: bold; color: #1e293b;">{{$sale['client_name']}}</div>
                <div style="font-size: 8.5pt; color: #64748b; margin-top: 2px;">{{$sale['client_adr']}}</div>
                <div style="font-size: 8.5pt; color: #64748b;">{{$sale['client_phone']}}</div>
            </td>
            <td style="width: 55%; vertical-align: top; text-align: right;">
                <span class="label">Date & Status</span>
                <div style="font-size: 9.5pt; font-weight: bold; color: #1e293b; margin-bottom: 3px;">{{$sale['date']}}</div>
                <div style="margin-bottom: 3px; {{ !empty($pdfT['show_status']) ? '' : 'display:none;' }}">
                    <span style="font-size: 8.5pt; font-weight: bold; color: #64748b; margin-right: 5px;">Sales Status:</span>
                    <span class="status-badge">{{$sale['statut']}}</span>
                </div>
                <div style="font-size: 8.5pt; font-weight: bold; color: #475569; margin-bottom: 3px;">
                    Payment Status: <span style="color: #2563eb; text-transform: uppercase;">{{$sale['payment_status']}}</span>
                </div>
                @if(!empty($sale['due_date']) && !empty($pdfT['show_due_date']))
                <div style="font-size: 8.5pt; font-weight: bold; color: {{ !empty($sale['is_overdue']) ? '#dc2626' : '#92400e' }};">
                    Due Date: <span>{{ $sale['due_date'] }}@if(!empty($sale['is_overdue'])) (Overdue)@endif</span>
                </div>
                @endif
            </td>
        </tr>
    </table>

    <table class="product-table" style="margin-bottom: 15px;">
        <thead>
            <tr>
                <th style="width: 6%; text-align: center;">#</th>
                <th style="width: {{ $descWidth }}%;">Description</th>
                @if($hasBoxQty) <th style="width: 8%; text-align: center;">Box</th> @endif
                <th style="width: 8%; text-align: center;">Qty</th>
                <th style="width: 12%; text-align: right;">Price</th>
                @if($hasLineDiscount) <th style="width: 10%; text-align: right;">Disc</th> @endif
                @if($hasLineTax) <th style="width: 10%; text-align: right;">VAT</th> @endif
                <th style="width: 18%; text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($details as $index => $detail)
            <tr>
                <td class="col-normal" style="text-align: center; color: #94a3b8; white-space: nowrap; font-size: 9pt;">
                    {{ sprintf('%02d', $index + 1) }}
                </td>
                <td class="col-normal">
                    <div class="product-name" style="font-weight: normal; color: #1e293b;">{{$detail['name']}}</div>
                    <div style="font-size: 7.5pt; color: #94a3b8; margin-top: 1px;">Code: {{$detail['code']}}</div>
                </td>
                @if($hasBoxQty) <td class="col-normal" style="text-align: center; color: #1e293b;">{{ $detail['box_qty'] ?? '—' }}</td> @endif
                <td class="col-normal" style="text-align: center; color: #1e293b;">{{$detail['quantity']}}</td>
                <td class="col-normal" style="text-align: right; color: #1e293b;">{{formatPrice($detail['price'], 2, $priceFormat)}}</td>
                @if($hasLineDiscount) <td class="col-normal" style="text-align: right; color: #1e293b;">{{formatPrice($detail['DiscountNet'], 2, $priceFormat)}}</td> @endif
                @if($hasLineTax) <td class="col-normal" style="text-align: right; color: #1e293b;">{{formatPrice($detail['taxe'], 2, $priceFormat)}}</td> @endif
                <td class="col-bold" style="text-align: right; color: #0f172a;">{{formatPrice($detail['total'], 2, $priceFormat)}}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-bottom: 15px;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 30px;">
                @if(!empty($pdfT['show_notes']) && (!empty($sale['notes']) || !empty($sale['note']) || !empty($sale['sale_note'])))
                <div style="margin-top: 3px;">
                    <span class="label" style="color: #2563eb;">Special Notes</span>
                    <div style="font-size: 8pt; color: #64748b; line-height: 1.3;">
                        {{ $sale['notes'] ?? $sale['note'] ?? $sale['sale_note'] }}
                    </div>
                </div>
                @endif
            </td>
            <td style="width: 50%;">
                <div class="calc-container">
                    <table style="width: 100%;">
                        <tr>
                            <td style="font-size: 8.5pt; color: #64748b;">Subtotal</td>
                            <td style="font-weight: bold; text-align: right;">{{$symbol}} {{formatPrice($subtotal, 2, $priceFormat)}}</td>
                        </tr>
                        @if($taxAmount > 0)
                        <tr>
                            <td style="font-size: 8.5pt; color: #64748b;">Order Tax</td>
                            <td style="font-weight: bold; text-align: right;">{{$symbol}} {{formatPrice($taxAmount, 2, $priceFormat)}}</td>
                        </tr>
                        @endif
                        @if($discountAmount > 0)
                        <tr>
                            <td style="font-size: 8.5pt; color: #64748b;">Discount</td>
                            <td style="font-weight: bold; text-align: right; color: #e11d48;">
                                @if($discountMethod === '1')
                                    - {{number_format($discountRaw, 2)}}% ({{$symbol}} {{formatPrice($discountAmount, 2, $priceFormat)}})
                                @else
                                    - {{$symbol}} {{formatPrice($discountAmount, 2, $priceFormat)}}
                                @endif
                            </td>
                        </tr>
                        @endif
                        @if($discountFromPoints > 0)
                        <tr>
                            <td style="font-size: 8.5pt; color: #64748b;">Discount from Points</td>
                            <td style="font-weight: bold; text-align: right; color: #e11d48;">- {{$symbol}} {{formatPrice($discountFromPoints, 2, $priceFormat)}}</td>
                        </tr>
                        @endif
                        @if($shippingAmount > 0)
                        <tr>
                            <td style="font-size: 8.5pt; color: #64748b; border-bottom: 2px solid #0f172a;">Shipping</td>
                            <td style="font-weight: bold; text-align: right; border-bottom: 2px solid #0f172a;">{{$symbol}} {{formatPrice($shippingAmount, 2, $priceFormat)}}</td>
                        </tr>
                        @endif
                        <tr style="background: #f8fafc;">
                            <td style="padding: 8px 12px; font-size: 9.5pt; font-weight: 900; color: #0f172a;">GRAND TOTAL</td>
                            <td style="padding: 8px 12px; font-size: 10.5pt; font-weight: 900; color: #0f172a; text-align: right;">{{$symbol}} {{formatPrice($sale['GrandTotal'], 2, $priceFormat)}}</td>
                        </tr>
                        <tr style="background: #f0fdf4;">
                            <td style="font-size: 8.5pt; color: #166534;">Paid Amount</td>
                            <td style="font-weight: bold; text-align: right; color: #166534;">{{$symbol}} {{formatPrice($sale['paid_amount'], 2, $priceFormat)}}</td>
                        </tr>
                        <tr style="background: #fff1f2;">
                            <td style="font-size: 8.5pt; color: #991b1b;">Balance Due</td>
                            <td style="font-weight: bold; text-align: right; color: #991b1b;">{{$symbol}} {{formatPrice($sale['due'], 2, $priceFormat)}}</td>
                        </tr>
                        @if(isset($sale['previous_dues']) && (float)$sale['previous_dues'] > 0 && !empty($pdfT['show_previous_dues']))
                        <tr style="background: #fffbeb;">
                            <td style="font-size: 8.5pt; color: #92400e;">Previous Dues</td>
                            <td style="font-weight: bold; text-align: right; color: #92400e;">{{$symbol}} {{formatPrice($sale['previous_dues'], 2, $priceFormat)}}</td>
                        </tr>
                        @endif
                        @if(isset($sale['previous_dues']) && (float)$sale['previous_dues'] > 0 && !empty($pdfT['show_net_balance']))
                        <tr style="background: #fffbeb;">
                            <td style="font-size: 8.5pt; color: #92400e; font-weight: bold;">Net Balance</td>
                            <td style="font-weight: bold; text-align: right; color: #92400e;">{{$symbol}} {{formatPrice((float)$sale['previous_dues'] + (float)$sale['due'], 2, $priceFormat)}}</td>
                        </tr>
                        @endif
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <div style="margin-top: 10px; page-break-inside: avoid; break-inside: avoid;">
        <div style="height: 1px; background: #e2e8f0; width: 100%; margin-bottom: 6px;"></div>
        <div style="text-align: center; {{ !empty($pdfT['show_thank_you']) ? '' : 'display:none;' }}">
            <div style="font-size: 9.5pt; font-weight: bold; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px;">{{ !empty($pdfT['labels']['thank_you']) ? $pdfT['labels']['thank_you'] : 'Thank you for your business' }}</div>
            <div style="font-size: 7.5pt; color: #94a3b8; margin-top: 2px;">This is a computer generated invoice.</div>
            <div style="font-size: 7.5pt; color: #94a3b8; margin-top: 1px;">Generated on: {{ date('M d, Y h:i A') }}</div>
        </div>
        @if(!empty($pdfT['show_footer_text']) && ($pdfT['footer_text'] !== '' || (!empty($setting['is_invoice_footer']) && !empty($setting['invoice_footer']))))
        <div style="text-align: center; margin-top: 6px;">
            <p style="font-size: 7.5pt; color: #6b7280; line-height: 1.5; margin: 0;">{{ $pdfT['footer_text'] !== '' ? $pdfT['footer_text'] : $setting['invoice_footer'] }}</p>
        </div>
        @endif
    </div>

</body>
</html>