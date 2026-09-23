{{--
    Single-Page Optimized Purchase Order Layout - Foolproof Margin Fix for Dompdf
--}}
@php
    $pdfLocale = app()->getLocale();
    $isRtl = $pdfLocale === 'ar';
@endphp
<!DOCTYPE html>
<html lang="{{ $pdfLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Purchase Order - {{ $po['Ref'] }}</title>
    <style>
        @page { 
            size: A4; 
            margin: 0; 
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8.5pt; 
            color: #334155; 
            line-height: 1.15;
            background-color: #ffffff;
            /* Foolproof margin fix for Dompdf */
            margin: 0;
            padding: 22px 25px; 
        }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        
        .product-table thead tr { background-color: #f8fafc; }
        .product-table th { padding: 6px 8px; color: #475569; font-size: 8pt; font-weight: bold; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .product-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        
        .info-card { border: 1px solid #e2e8f0; border-radius: 4px; overflow: hidden; background: #f8fafc; }
        .info-card-header { background: #4338ca; color: #fff; padding: 5px 8px; font-size: 7.5pt; font-weight: bold; text-transform: uppercase; }
        .info-card-body { padding: 8px; }
    </style>
</head>
<body>

    <!-- Header Section -->
    <table style="margin-bottom: 12px;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                @php
                    $logoSrc = null;
                    if (!empty($company['logo'])) {
                        $logoPath = public_path('images/'.$company['logo']);
                        if (file_exists($logoPath)) {
                            $logoSrc = 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath));
                        }
                    }
                @endphp
                <table>
                    <tr>
                        @if(!empty($logoSrc))
                        <td style="width: 65px; vertical-align: top; padding-right: 10px;">
                            <img src="{{ $logoSrc }}" style="max-height: 45px; max-width: 130px;">
                        </td>
                        @endif
                        <td style="vertical-align: top;">
                            <div style="font-size: 10pt; font-weight: bold; color: #0f172a;">{{ $company['name'] }}</div>
                            <div style="font-size: 7.5pt; color: #64748b; margin-top: 2px; line-height: 1.3;">
                                @if(!empty($company['address'])) {{ $company['address'] }}<br> @endif
                                @if(!empty($company['vat_number'])) VAT/BIN: {{ $company['vat_number'] }}<br> @endif
                                @if(!empty($company['website'])) {{ $company['website'] }}<br> @endif
                                @if(!empty($company['phone'])) Phone: {{ $company['phone'] }} @endif
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 45%; text-align: right; vertical-align: top;">
                <div style="font-size: 16pt; font-weight: 800; color: #4338ca; line-height: 1;">PURCHASE ORDER</div>
                <div style="font-size: 9pt; font-weight: bold; color: #475569; margin-top: 3px;">Ref: {{ $po['Ref'] }}</div>
                <div style="margin-top: 5px; font-size: 8pt;">
                    <div><span style="color: #64748b;">Date:</span> <strong style="color: #0f172a;">{{ $po['date'] }}</strong></div>
                    <div style="margin-top: 2px;"><span style="color: #64748b;">Expected Delivery:</span> <strong style="color: #0f172a;">{{ $po['expected_delivery_date'] ?? '—' }}</strong></div>
                    <div style="margin-top: 4px;">
                        <span style="background: {{ $statusStyle['bg'] ?? '#eef2ff' }}; color: {{ $statusStyle['color'] ?? '#4338ca' }}; padding: 2px 6px; border-radius: 3px; font-size: 7pt; font-weight: bold; text-transform: uppercase;">{{ str_replace('_', ' ', $po['status']) }}</span>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <div style="height: 1px; background: #e2e8f0; margin-bottom: 12px;"></div>

    <!-- Supplier & Warehouse Info -->
    <table style="margin-bottom: 12px;" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width: 48%; vertical-align: top;">
                <table class="info-card">
                    <tr><td class="info-card-header">Supplier Info</td></tr>
                    <tr>
                        <td class="info-card-body" style="vertical-align: top;">
                            <div style="font-size: 8.5pt; font-weight: bold; color: #1e293b; margin-bottom: 3px;">{{ $po['supplier_name'] }}</div>
                            <div style="font-size: 7.5pt; color: #64748b; line-height: 1.3;">
                                @if(!empty($po['supplier_phone'])) Phone: {{ $po['supplier_phone'] }}<br> @endif
                                @if(!empty($po['supplier_email'])) Email: {{ $po['supplier_email'] }}<br> @endif
                                @if(!empty($po['supplier_address'])) {{ $po['supplier_address'] }} @endif
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%; vertical-align: top;">
                <table class="info-card">
                    <tr><td class="info-card-header">Deliver To (Warehouse)</td></tr>
                    <tr>
                        <td class="info-card-body" style="vertical-align: top;">
                            <div style="font-size: 8.5pt; font-weight: bold; color: #1e293b; margin-bottom: 3px;">{{ $po['warehouse_name'] ?? 'Main Warehouse' }}</div>
                            <div style="font-size: 7.5pt; color: #64748b; line-height: 1.3;">Destination warehouse facility for order delivery.</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Items Table -->
    <table class="product-table" style="margin-bottom: 12px;">
        <thead>
            <tr>
                <th style="width: 6%; text-align: center;">#</th>
                <th style="width: 50%;">Product</th>
                <th style="width: 12%; text-align: center;">Qty</th>
                <th style="width: 16%; text-align: right;">Unit Cost</th>
                <th style="width: 16%; text-align: right;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $index => $line)
            <tr>
                <td style="text-align: center; color: #94a3b8; font-size: 8pt;">{{ sprintf('%02d', $index + 1) }}</td>
                <td>
                    <div style="font-weight: normal; color: #1e293b;">{{ $line['name'] }}</div>
                    <div style="font-size: 7pt; color: #94a3b8; margin-top: 1px;">Code: {{ $line['code'] }}</div>
                </td>
                <td style="text-align: center; color: #1e293b; font-weight: bold;">{{ $line['quantity'] }}</td>
                <td style="text-align: right; color: #334155;">{{ $symbol }} {{ $line['cost'] }}</td>
                <td style="text-align: right; font-weight: bold; color: #0f172a;">{{ $symbol }} {{ $line['total'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Totals Section -->
    <table style="margin-bottom: 12px;">
        <tr>
            <td style="width: 55%;"></td>
            <td style="width: 45%;">
                <table style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px;">
                    <tr><td style="padding: 5px 8px; font-size: 8pt; color: #64748b;">Tax</td><td style="padding: 5px 8px; font-size: 8pt; text-align: right; font-weight: 600;">{{ $symbol }} {{ $po['TaxNet'] ?? '0.00' }}</td></tr>
                    <tr><td style="padding: 5px 8px; font-size: 8pt; color: #64748b;">Discount</td><td style="padding: 5px 8px; font-size: 8pt; text-align: right; font-weight: 600; color: #e11d48;">- {{ $symbol }} {{ $po['discount'] ?? '0.00' }}</td></tr>
                    <tr><td style="padding: 5px 8px; font-size: 8pt; color: #64748b; border-bottom: 1px solid #e2e8f0;">Shipping</td><td style="padding: 5px 8px; font-size: 8pt; text-align: right; font-weight: 600; border-bottom: 1px solid #e2e8f0;">{{ $symbol }} {{ $po['shipping'] ?? '0.00' }}</td></tr>
                    <tr style="background: #4338ca;">
                        <td style="padding: 6px 8px; font-size: 9pt; font-weight: bold; color: #fff;">TOTAL</td>
                        <td style="padding: 6px 8px; font-size: 9.5pt; font-weight: bold; color: #fff; text-align: right;">{{ $symbol }} {{ $po['GrandTotal'] }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Notes & Footer -->
    @if(!empty($po['notes']))
    <div style="margin-bottom: 10px;">
        <div style="font-size: 7.5pt; font-weight: bold; color: #4338ca; text-transform: uppercase; margin-bottom: 2px;">Notes</div>
        <div style="font-size: 8pt; color: #475569; background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px; border-radius: 3px;">{{ $po['notes'] }}</div>
    </div>
    @endif

    <div style="margin-top: 15px; border-top: 1px solid #e2e8f0; padding-top: 8px; text-align: center;">
        <div style="font-size: 7pt; color: #94a3b8;">This is a Purchase Order, not an invoice. No payment is due upon receipt. Generated on: {{ date('M d, Y h:i A') }}</div>
    </div>

</body>
</html>