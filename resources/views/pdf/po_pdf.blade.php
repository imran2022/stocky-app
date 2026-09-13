<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Purchase Order - {{ $po['Ref'] }}</title>
    <style>
        @page { size: A4; margin: 15mm 18mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1f2937; line-height: 1.4; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #6b7280; }
        .right { text-align: right; }
        .center { text-align: center; }
    </style>
</head>
<body>
    <table style="margin-bottom: 14px;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                @if($logoSrc)
                    <img src="{{ $logoSrc }}" alt="Logo" style="max-height: 55px; max-width: 200px; margin-bottom: 6px;">
                @endif
                <div style="font-size: 12pt; font-weight: bold;">{{ $company['name'] }}</div>
                <div class="muted" style="font-size: 8.5pt; line-height: 1.5;">
                    {{ $company['phone'] }}<br>
                    {{ $company['address'] }}
                </div>
            </td>
            <td style="width: 45%; vertical-align: top; text-align: right;">
                <div style="font-size: 16pt; font-weight: bold; color: #4338ca; margin-bottom: 6px;">PURCHASE ORDER</div>
                <div style="display: inline-block; background: #eef2ff; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 10pt; color: #4338ca;">{{ $po['Ref'] }}</div>
                <table style="width: 100%; font-size: 8.5pt; margin-top: 8px;">
                    <tr><td class="muted right" style="padding: 2px 0;">Date:</td><td class="right" style="padding: 2px 0; font-weight: 600;">{{ $po['date'] }}</td></tr>
                    <tr><td class="muted right" style="padding: 2px 0;">Expected Delivery:</td><td class="right" style="padding: 2px 0; font-weight: 600;">{{ $po['expected_delivery_date'] ?? '—' }}</td></tr>
                    <tr>
                        <td class="muted right" style="padding: 2px 0;">Status:</td>
                        <td class="right" style="padding: 2px 0;">
                            <span style="background: {{ $statusStyle['bg'] }}; color: {{ $statusStyle['color'] }}; padding: 2px 8px; border-radius: 3px; font-size: 7.5pt; font-weight: bold; text-transform: uppercase;">{{ str_replace('_', ' ', $po['status']) }}</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div style="height: 2px; background: #4338ca; margin-bottom: 12px;"></div>

    <table style="margin-bottom: 16px;">
        <tr>
            <td style="width: 48%; vertical-align: top;">
                <div style="border: 1px solid #e5e7eb; border-radius: 4px; overflow: hidden;">
                    <div style="background: #4338ca; color: #fff; padding: 5px 10px; font-size: 8.5pt; font-weight: bold; text-transform: uppercase;">Supplier</div>
                    <div style="padding: 8px 10px;">
                        <div style="font-weight: bold; margin-bottom: 3px;">{{ $po['supplier_name'] }}</div>
                        <div class="muted" style="font-size: 8pt; line-height: 1.5;">
                            {{ $po['supplier_phone'] }}<br>
                            {{ $po['supplier_email'] }}<br>
                            {{ $po['supplier_address'] }}
                        </div>
                    </div>
                </div>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%; vertical-align: top;">
                <div style="border: 1px solid #e5e7eb; border-radius: 4px; overflow: hidden;">
                    <div style="background: #4338ca; color: #fff; padding: 5px 10px; font-size: 8.5pt; font-weight: bold; text-transform: uppercase;">Deliver To</div>
                    <div style="padding: 8px 10px;">
                        <div style="font-weight: bold;">{{ $po['warehouse_name'] }}</div>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <table style="margin-bottom: 12px;">
        <thead>
            <tr style="background: #f3f4f6;">
                <th class="right" style="padding: 6px 8px; font-size: 8pt; text-transform: uppercase; width: 5%;">#</th>
                <th style="padding: 6px 8px; font-size: 8pt; text-transform: uppercase; text-align: left;">Product</th>
                <th class="center" style="padding: 6px 8px; font-size: 8pt; text-transform: uppercase; width: 12%;">Qty</th>
                <th class="right" style="padding: 6px 8px; font-size: 8pt; text-transform: uppercase; width: 18%;">Unit Cost</th>
                <th class="right" style="padding: 6px 8px; font-size: 8pt; text-transform: uppercase; width: 18%;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $i => $line)
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td class="right muted" style="padding: 6px 8px; font-size: 8.5pt;">{{ $i + 1 }}</td>
                    <td style="padding: 6px 8px; font-size: 9pt;">
                        {{ $line['name'] }}
                        <div class="muted" style="font-size: 7.5pt;">{{ $line['code'] }}</div>
                    </td>
                    <td class="center" style="padding: 6px 8px; font-size: 9pt;">{{ $line['quantity'] }}</td>
                    <td class="right" style="padding: 6px 8px; font-size: 9pt;">{{ $symbol }} {{ $line['cost'] }}</td>
                    <td class="right" style="padding: 6px 8px; font-size: 9pt; font-weight: 600;">{{ $symbol }} {{ $line['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-bottom: 16px;">
        <tr>
            <td style="width: 60%;"></td>
            <td style="width: 40%;">
                <table>
                    <tr><td class="muted" style="padding: 3px 8px; font-size: 8.5pt;">Tax</td><td class="right" style="padding: 3px 8px; font-size: 8.5pt;">{{ $symbol }} {{ $po['TaxNet'] }}</td></tr>
                    <tr><td class="muted" style="padding: 3px 8px; font-size: 8.5pt;">Discount</td><td class="right" style="padding: 3px 8px; font-size: 8.5pt;">- {{ $symbol }} {{ $po['discount'] }}</td></tr>
                    <tr><td class="muted" style="padding: 3px 8px; font-size: 8.5pt;">Shipping</td><td class="right" style="padding: 3px 8px; font-size: 8.5pt;">{{ $symbol }} {{ $po['shipping'] }}</td></tr>
                    <tr style="background: #4338ca;">
                        <td style="padding: 8px; font-size: 10.5pt; font-weight: bold; color: #fff;">TOTAL</td>
                        <td class="right" style="padding: 8px; font-size: 10.5pt; font-weight: bold; color: #fff;">{{ $symbol }} {{ $po['GrandTotal'] }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if(!empty($po['notes']))
        <div style="border-top: 1px solid #e5e7eb; padding-top: 10px; margin-top: 10px;">
            <div class="muted" style="font-size: 8pt; font-weight: bold; text-transform: uppercase; margin-bottom: 3px;">Notes</div>
            <div style="font-size: 9pt;">{{ $po['notes'] }}</div>
        </div>
    @endif

    <div style="margin-top: 30px; padding-top: 10px; border-top: 1px solid #e5e7eb;" class="muted center">
        <div style="font-size: 7.5pt;">This is a Purchase Order, not an invoice. No payment is due upon receipt of this document.</div>
    </div>
</body>
</html>
