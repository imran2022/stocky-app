{{--
    "Modern" Payment Receipt layout — Matching modern invoice design standard.
--}}
@php
    $pdfLocale = app()->getLocale();
    $isRtl = $pdfLocale === 'ar';
    $rtlLabelSuffix = $isRtl ? '' : ':';

    $isPdfDownload = request()->is('*pdf*') || request()->is('*download*') || request()->has('pdf');
    $pdfT = \App\Models\PdfTemplate::settingsFor('payment'); // Falls back smoothly if settings exist
@endphp
<!DOCTYPE html>
<html lang="{{ $pdfLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Payment Receipt - {{$payment['Ref']}}</title>
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
        .receipt-table thead tr { background-color: #f8fafc; }
        .receipt-table th { padding: 8px 10px; color: #475569; font-size: 8.5pt; font-weight: bold; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .receipt-table td { padding: 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; line-height: 1.2; }
        .receipt-table tbody { page-break-inside: avoid; break-inside: avoid; }
        .info-card { border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; background: #f8fafc; }
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
                <div style="font-size: 18pt; font-weight: 800; color: #0f172a; line-height: 1;">{{ __('pdf.payment_receipt') }}</div>
                <div style="font-size: 10pt; font-weight: bold; color: #475569; margin-top: 4px;">Receipt No: {{$payment['Ref']}}</div>
                <div style="margin-top: 4px;">
                    <span style="color: #10b981; font-weight: bold; font-size: 9pt;">Amount: {{$symbol}} {{formatPrice((float)$payment['montant'], 2, $priceFormat)}}</span>
                </div>
            </td>
        </tr>
    </table>

    <div style="height: 1px; background: #f1f5f9; margin-bottom: 15px;"></div>

    <table style="margin-bottom: 20px;">
        <tr>
            <td style="width: 45%; vertical-align: top;">
                <span class="label">{{ __('pdf.received_from') }}</span>
                <div style="font-size: 9.5pt; font-weight: bold; color: #1e293b;">{{$payment['client_name']}}</div>
                @if(!empty($payment['client_adr']))
                <div style="font-size: 8.5pt; color: #64748b; margin-top: 2px;">{{$payment['client_adr']}}</div>
                @endif
                @if(!empty($payment['client_phone']))
                <div style="font-size: 8.5pt; color: #64748b;">{{ __('pdf.phone') }}: {{$payment['client_phone']}}</div>
                @endif
                @if(!empty($payment['client_email']))
                <div style="font-size: 8.5pt; color: #64748b;">{{ __('pdf.email') }}: {{$payment['client_email']}}</div>
                @endif
            </td>
            <td style="width: 10%;"></td>
            <td style="width: 45%; vertical-align: top; text-align: right;">
                <span class="label">{{ __('pdf.date') }}</span>
                <div style="font-size: 9.5pt; font-weight: bold; color: #1e293b; margin-bottom: 6px;">
                    @php
                        $dateFormat = $setting['date_format'] ?? 'YYYY-MM-DD';
                        $dateTime = \Carbon\Carbon::parse($payment['date']);
                        $phpDateFormat = str_replace(['YYYY', 'MM', 'DD'], ['Y', 'm', 'd'], $dateFormat);
                        $hasTime = strpos($payment['date'], ' ') !== false && preg_match('/\d{1,2}:\d{2}/', $payment['date']);
                        if ($hasTime) {
                            $formattedDate = $dateTime->format($phpDateFormat . ' H:i');
                            if (preg_match('/:\d{2}:\d{2}/', $payment['date'])) {
                                $formattedDate = $dateTime->format($phpDateFormat . ' H:i:s');
                            }
                        } else {
                            $formattedDate = $dateTime->format($phpDateFormat);
                        }
                    @endphp
                    {{$formattedDate}}
                </div>
                <span class="label">{{ __('pdf.payment_method') }}</span>
                <div style="font-size: 9.5pt; font-weight: bold; color: #2563eb; text-transform: uppercase;">
                    {{$payment['payment_method']}}
                </div>
            </td>
        </tr>
    </table>

    <table class="receipt-table" style="margin-bottom: 20px;">
        <thead>
            <tr>
                <th style="width: 50%;">{{ __('pdf.sale_reference') }}</th>
                <th style="width: 50%; text-align: right;">{{ __('pdf.amount_paid') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="font-weight: bold; color: #1e293b; font-size: 10pt;">
                    {{$payment['sale_Ref']}}
                </td>
                <td style="text-align: right; font-weight: bold; color: #10b981; font-size: 11pt;">
                    {{$symbol}} {{formatPrice((float)$payment['montant'], 2, $priceFormat)}}
                </td>
            </tr>
        </tbody>
    </table>

    <table style="margin-bottom: 20px;">
        <tr>
            <td style="width: 100%;">
                <div class="info-card" style="text-align: center; background: #f8fafc; padding: 15px;">
                    <div style="font-size: 8pt; color: #64748b; text-transform: uppercase; font-weight: bold; margin-bottom: 4px;">{{ __('pdf.total_amount_received') }}</div>
                    <div style="font-size: 16pt; font-weight: 900; color: #0f172a;">{{$symbol}} {{formatPrice((float)$payment['montant'], 2, $priceFormat)}}</div>
                </div>
            </td>
        </tr>
    </table>

    <div style="margin-top: 15px; page-break-inside: avoid; break-inside: avoid;">
        <div style="height: 1px; background: #e2e8f0; width: 100%; margin-bottom: 10px;"></div>
        <div style="text-align: center;">
            <div style="font-size: 9.5pt; font-weight: bold; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px;">{{ __('pdf.thank_you_payment') }}</div>
            <div style="font-size: 7.5pt; color: #94a3b8; margin-top: 2px;">This is a computer generated payment receipt.</div>
            <div style="font-size: 7.5pt; color: #94a3b8; margin-top: 1px;">Generated on: {{ date('M d, Y h:i A') }}</div>
        </div>
        @if(!empty($setting['is_invoice_footer']) && !empty($setting['invoice_footer']))
        <div style="text-align: center; margin-top: 8px;">
            <p style="font-size: 7.5pt; color: #6b7280; line-height: 1.5; margin: 0;">{{ $setting['invoice_footer'] }}</p>
        </div>
        @endif
    </div>

</body>
</html>