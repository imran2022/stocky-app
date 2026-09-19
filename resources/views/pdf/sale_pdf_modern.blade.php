{{--
    "Modern" Sale Invoice layout — Build L3 (2026-09-19). One of two
    selectable layouts for the Sales Invoice PDF (Settings → Invoice PDF →
    Sales Invoice → Template). Supplied as a ready-made design; the only
    change made to it here is wiring in the Previous Dues / Net Balance
    toggle (Build L2) below, so both layouts stay in sync with that
    setting. Unlike the Classic layout, this one does not read the
    Colors/Typography/Layout/Items-table panels of the Invoice PDF
    customizer — its colors and spacing are fixed in its own <style>
    block, by design (it was supplied fully styled).
--}}
@php
    $pdfLocale = app()->getLocale();
    $isRtl = $pdfLocale === 'ar';
    $rtlLabelSuffix = $isRtl ? '' : ':';

    // Check if it is PDF Download or Browser View
    $isPdfDownload = request()->is('*pdf*') || request()->is('*download*') || request()->has('pdf');

    // Same on/off switches the Classic layout and Sale Detail page use.
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
        $discountAmount = (float)($sale['discount'] ?? 0);
        $shippingAmount = (float)($sale['shipping'] ?? 0);
        $subtotal = $sale['GrandTotal'] - $shippingAmount - $taxAmount + $discountAmount;

        // Dynamic Column Checkers
        $hasLineDiscount = false;
        $hasLineTax = false;
        foreach ($details as $detail) {
            if ((float)($detail['DiscountNet'] ?? 0) > 0) {
                $hasLineDiscount = true;
            }
            if ((float)($detail['taxe'] ?? 0) > 0) {
                $hasLineTax = true;
            }
        }

        // Dynamic Width Adjustment
        $descWidth = 37; 
        if (!$hasLineDiscount) $descWidth += 10;
        if (!$hasLineTax) $descWidth += 10;
    @endphp
    <style>
        @page { 
            size: A4; 
            @if($isPdfDownload)
                margin: 0; 
            @else
                margin: 0.4in; 
            @endif
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9pt; 
            color: #334155; 
            line-height: 1.25;
            @if($isPdfDownload)
                padding: 25px 25px 35px 25px; 
            @else
                padding: 0;
            @endif
            background-color: #ffffff;
        }
        
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .label { font-size: 7.5pt; font-weight: bold; color: #94a3b8; text-transform: uppercase; margin-bottom: 1px; display: block; }
        
        .product-table thead tr { background-color: #f8fafc; }
        .product-table th { padding: 5px 10px; color: #475569; font-size: 8.5pt; font-weight: bold; text-align: left; border-bottom: 1px solid #e2e8f0; }
        
        .product-table td { 
            padding: 6px 10px; 
            border-bottom: 1px solid #f1f5f9; 
            vertical-align: middle; 
            line-height: 1.2;
        }
        
        .product-table tbody tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .col-normal { font-weight: normal !important; }
        .col-bold { font-weight: bold !important; }
        .product-name { color: #1e293b; }
        
        .status-badge { 
            background: #2563eb; 
            color: #ffffff; 
            padding: 2px 10px; 
            border-radius: 4px; 
            font-size: 7.5pt; 
            font-weight: bold; 
            text-transform: uppercase; 
        }
        
        .calc-container { 
            border: 1px solid #e2e8f0; 
            border-radius: 6px; 
            overflow: hidden; 
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .calc-container td { padding: 4px 12px; border-bottom: 1px solid #f1f5f9; }

        /* ================================================================= */
        /* 📦 LIGHTWEIGHT COURIER HUB SHIPPING LABEL                        */
        /* ================================================================= */
        .shipping-wrapper {
            margin-top: 35px;
            page-break-inside: avoid; 
            break-inside: avoid;
        }
        
        .cut-line {
            border-top: 2px dashed #94a3b8;
            text-align: center;
            height: 14px;
            margin-bottom: 12px;
            position: relative;
        }
        .cut-line span {
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: #fff;
            padding: 0 15px;
            font-size: 8.5pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }
        
        /* 🟢 Halka & Soft Light Outer Box (With 10px Smooth Corner Radius) */
        .hub-label-box {
            border: 1px solid #cbd5e1; /* Subtle & thin grey border */
            border-radius: 10px;
            background: #ffffff;
            overflow: hidden;
        }

        .hub-cell {
            padding: 12px 14px;
            vertical-align: top;
        }

        .hub-title-tag {
            font-size: 7.5pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
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
                            <div style="font-size: 8pt; color: #64748b;">{{$setting['CompanyPhone']}} | {{$setting['email']}}</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 40%; text-align: right; vertical-align: top;">
                <div style="font-size: 20pt; font-weight: 800; color: #0f172a; line-height: 1;">INVOICE</div>
                <div style="font-size: 10pt; font-weight: bold; color: #475569; margin-top: 2px;">Invoice No: {{$sale['Ref']}}</div>
                <div style="margin-top: 4px;">
                    <span style="color: #10b981; font-weight: bold; font-size: 8.5pt;">Paid: {{$symbol}} {{formatPrice($sale['paid_amount'], 2, $priceFormat)}}</span>
                    <span style="color: #e11d48; font-weight: bold; font-size: 8.5pt; margin-left: 12px;">Due: {{$symbol}} {{formatPrice($sale['due'], 2, $priceFormat)}}</span>
                </div>
            </td>
        </tr>
    </table>

    <div style="height: 1px; background: #f1f5f9; margin-bottom: 12px;"></div>

    <table style="margin-bottom: 15px;">
        <tr>
            <td style="width: 45%; vertical-align: top;">
                <span class="label">Customer</span>
                <div style="font-size: 9.5pt; font-weight: bold; color: #1e293b;">{{$sale['client_name']}}</div>
                <div style="font-size: 8.5pt; color: #64748b; margin-top: 2px;">{{$sale['client_adr']}}</div>
                <div style="font-size: 8.5pt; color: #64748b;">{{$sale['client_phone']}}</div>
            </td>
            <td style="width: 55%; vertical-align: top; text-align: right;">
                <span class="label">Date & Status</span>
                <div style="font-size: 9.5pt; font-weight: bold; color: #1e293b; margin-bottom: 3px;">{{$sale['date']}}</div>
                <div style="margin-bottom: 3px;">
                    <span style="font-size: 8.5pt; font-weight: bold; color: #64748b; margin-right: 5px;">Sales Status:</span>
                    <span class="status-badge">{{$sale['statut']}}</span>
                </div>
                <div style="font-size: 8.5pt; font-weight: bold; color: #475569;">
                    Payment Status: <span style="color: #2563eb; text-transform: uppercase;">{{$sale['payment_status']}}</span>
                </div>
            </td>
        </tr>
    </table>

    <table class="product-table" style="margin-bottom: 15px;">
        <thead>
            <tr>
                <th style="width: 6%; text-align: center;">#</th>
                <th style="width: {{ $descWidth }}%;">Description</th>
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
                @if(!empty($sale['notes']) || !empty($sale['note']) || !empty($sale['sale_note']))
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
                            <td style="font-weight: bold; text-align: right; color: #e11d48;">- {{$symbol}} {{formatPrice($discountAmount, 2, $priceFormat)}}</td>
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
        <div style="text-align: center;">
            <div style="font-size: 9.5pt; font-weight: bold; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px;">Thank you for your business</div>
            <div style="font-size: 7.5pt; color: #94a3b8; margin-top: 2px;">This is a computer generated invoice.</div>
            <div style="font-size: 7.5pt; color: #94a3b8; margin-top: 1px;">Generated on: {{ date('M d, Y h:i A') }}</div>
        </div>
    </div>


    <div class="shipping-wrapper">
        <div class="cut-line">
        </div>
        
        <div class="hub-label-box">
            
            <div style="background: #ffffff; color: #0f172a; border-bottom: 1px solid #cbd5e1; padding: 10px 14px; font-size: 11pt; font-weight: 800; letter-spacing: 1px; text-transform: uppercase;">
                DELIVERY INFORMATION
            </div>

            <table style="width: 100%; border-bottom: 1px solid #e2e8f0;">
                <tr>
                    <td style="width: 50%; border-right: 1px solid #e2e8f0;" class="hub-cell">
                        <div class="hub-title-tag">Sender (From):</div>
                        <div style="font-size: 9.5pt; font-weight: bold; color: #0f172a;">{{$setting['CompanyName']}}</div>
                        <div style="font-size: 8pt; color: #475569; margin-top: 2px;">{{$setting['CompanyAdress']}}</div>
                        <div style="font-size: 8pt; color: #475569; font-weight: bold; margin-top: 2px;">Phone: {{$setting['CompanyPhone']}}</div>
                    </td>
                    <td style="width: 50%;" class="hub-cell">
                        <div class="hub-title-tag">Shipment Ref:</div>
                        <div style="font-size: 8.5pt; color: #1e293b; line-height: 1.4;">
                            <strong>Invoice No:</strong> {{$sale['Ref']}}<br>
                            <strong>Date:</strong> {{$sale['date']}}<br>
                            <div style="font-size: 9pt; font-weight: bold; color: #0f172a; margin-top: 4px; border-top: 1px dashed #cbd5e1; padding-top: 3px;">
                                <strong>Order Total:</strong> {{$symbol}} {{formatPrice($sale['GrandTotal'], 2, $priceFormat)}}
                            </div>
                        </div>
                    </td>
                </tr>
            </table>

            <table style="width: 100%;">
                <tr>
                    <td style="width: 55%; border-right: 1px solid #e2e8f0;" class="hub-cell">
                        <div class="hub-title-tag" style="color: #2563eb; font-weight: bold;">Ship To (Receiver):</div>
                        <div style="font-size: 11pt; font-weight: bold; color: #0f172a; line-height: 1.2;">{{$sale['client_name']}}</div>
                        <div style="font-size: 9.5pt; color: #334155; font-weight: 500; margin-top: 4px; line-height: 1.4;">{{$sale['client_adr']}}</div>
                        <div style="font-size: 10.5pt; font-weight: bold; color: #0f172a; margin-top: 6px;">Phone: {{$sale['client_phone']}}</div>
                    </td>
                    
                    <td style="width: 45%; padding: 15px; vertical-align: middle; text-align: center;">
                        @if(in_array(strtoupper($sale['payment_status']), ['PARTIAL', 'UNPAID', 'NOT PAID', 'DUE']) && (float)$sale['due'] > 0)
                            <div style="background: #fff5f5; border: 1px dashed #e11d48; border-radius: 6px; padding: 12px 6px;">
                                <span style="font-size: 8pt; font-weight: bold; color: #991b1b; text-transform: uppercase; display: block; letter-spacing: 0.5px; margin-bottom: 2px;">Cash On Delivery (COD)</span>
                                <span style="font-size: 14pt; font-weight: 900; color: #e11d48;">{{$symbol}} {{formatPrice($sale['due'], 2, $priceFormat)}}</span>
                            </div>
                        @else
                            <div style="background: #f0fdf4; border: 1px dashed #166534; border-radius: 6px; padding: 15px 6px;">
                                <span style="font-size: 12pt; font-weight: 900; color: #166534; text-transform: uppercase; letter-spacing: 2px; display: block;">PAID</span>
                            </div>
                        @endif
                    </td>
                </tr>
            </table>

        </div>
    </div>

</body>
</html>