<!--
    Standalone Shipping Label PDF (Build L4, 2026-09-19). Restyled to match
    the "DELIVERY INFORMATION" shipping-label section embedded at the
    bottom of the Modern Sale Invoice (sale_pdf_modern.blade.php) — same
    card look, uppercase micro-labels, sender/receiver block layout, and
    COD vs PAID badge — so both feel like one consistent design language.
    Inputs ($sale, $company, $symbol) are unchanged from
    SalesController::Sale_Shipping_Label(); no controller change needed.
-->
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 12px; }
    body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 9pt; margin: 0; }

    .cut-line {
        border-top: 2px dashed #94a3b8;
        text-align: center;
        height: 12px;
        margin-bottom: 10px;
        position: relative;
    }
    .cut-line span {
        position: absolute;
        top: -10px;
        left: 50%;
        transform: translateX(-50%);
        background: #fff;
        padding: 0 10px;
        font-size: 7pt;
        font-weight: bold;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 1.2px;
    }

    .hub-label-box {
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        background: #ffffff;
        overflow: hidden;
    }
    .hub-title-bar {
        background: #ffffff;
        color: #0f172a;
        border-bottom: 1px solid #cbd5e1;
        padding: 8px 12px;
        font-size: 9.5pt;
        font-weight: 800;
        letter-spacing: 1px;
        text-transform: uppercase;
    }
    .hub-cell { padding: 10px 12px; vertical-align: top; }
    .hub-title-tag {
        font-size: 7pt;
        font-weight: bold;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }
</style>
</head>
<body>

    <div class="cut-line"><span>Shipping Label</span></div>

    <div class="hub-label-box">
        <div class="hub-title-bar">Delivery Information</div>

        <table style="width: 100%; border-bottom: 1px solid #e2e8f0;">
            <tr>
                <td class="hub-cell">
                    <div class="hub-title-tag">Sender (From):</div>
                    <div style="font-size: 9pt; font-weight: bold; color: #0f172a;">{{ $company['CompanyName'] }}</div>
                    @if(!empty($company['CompanyAdress']))<div style="font-size: 7.5pt; color: #475569; margin-top: 2px;">{{ $company['CompanyAdress'] }}</div>@endif
                    @if(!empty($company['CompanyPhone']))<div style="font-size: 7.5pt; color: #475569; font-weight: bold; margin-top: 2px;">Phone: {{ $company['CompanyPhone'] }}</div>@endif
                    @if(!empty($company['vat_number']))<div style="font-size: 7pt; color: #64748b; margin-top: 1px;">VAT/BIN: {{ $company['vat_number'] }}</div>@endif
                </td>
            </tr>
        </table>

        <table style="width: 100%; border-bottom: 1px solid #e2e8f0;">
            <tr>
                <td class="hub-cell">
                    <div class="hub-title-tag">Shipment Ref:</div>
                    <div style="font-size: 8pt; color: #1e293b; line-height: 1.4;">
                        <strong>Invoice No:</strong> {{ $sale['Ref'] }}<br>
                        <strong>Date:</strong> {{ $sale['date'] }}
                        <div style="font-size: 8.5pt; font-weight: bold; color: #0f172a; margin-top: 4px; border-top: 1px dashed #cbd5e1; padding-top: 3px;">
                            Order Total: {{ $symbol }} {{ $sale['GrandTotal'] }}
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        <table style="width: 100%;">
            <tr>
                <td class="hub-cell">
                    <div class="hub-title-tag" style="color: #2563eb; font-weight: bold;">Ship To (Receiver):</div>
                    <div style="font-size: 11pt; font-weight: bold; color: #0f172a; line-height: 1.2;">{{ $sale['client_name'] }}</div>
                    @if(!empty($sale['client_adr']))<div style="font-size: 8.5pt; color: #334155; font-weight: 500; margin-top: 4px; line-height: 1.4;">{{ $sale['client_adr'] }}</div>@endif
                    @if(!empty($sale['client_phone']))<div style="font-size: 9.5pt; font-weight: bold; color: #0f172a; margin-top: 6px;">Phone: {{ $sale['client_phone'] }}</div>@endif
                </td>
            </tr>
        </table>

        <table style="width: 100%;">
            <tr>
                <td class="hub-cell" style="text-align: center;">
                    @if((float) ($sale['cod_amount'] ?? 0) > 0)
                        <div style="background: #fff5f5; border: 1px dashed #e11d48; border-radius: 6px; padding: 10px 6px;">
                            <span style="font-size: 7.5pt; font-weight: bold; color: #991b1b; text-transform: uppercase; display: block; letter-spacing: 0.5px; margin-bottom: 2px;">Cash On Delivery (COD)</span>
                            <span style="font-size: 13pt; font-weight: 900; color: #e11d48;">{{ $symbol }}{{ $sale['cod_amount'] }}</span>
                        </div>
                    @else
                        <div style="background: #f0fdf4; border: 1px dashed #166534; border-radius: 6px; padding: 12px 6px;">
                            <span style="font-size: 11pt; font-weight: 900; color: #166534; text-transform: uppercase; letter-spacing: 2px; display: block;">Paid</span>
                        </div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

</body>
</html>
