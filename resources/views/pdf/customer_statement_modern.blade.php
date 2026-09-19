{{--
    Customer Account Statement — Modern layout (Build M4, 2026-09-19).
    Styled to match the "Modern" Sale Invoice layout
    (resources/views/pdf/sale_pdf_modern.blade.php): same slate/blue palette,
    same header block (logo + company on the left, big title on the right),
    same label/value typography. Not user-customizable (self-styled by
    design, same as the Modern invoice).

    Data: $client, $entries, $opening_balance, $current_opening_balance,
    $closing_balance, $fromDate, $toDate, $setting, $symbol, $priceFormat —
    all supplied by ClientStatementController@pdf, built from the same
    App\Services\ClientStatementService the customer portal statement uses.
--}}
@php
    $pdfLocale = app()->getLocale();
    $isRtl = $pdfLocale === 'ar';

    if (!function_exists('stmtFormatPrice')) {
        function stmtFormatPrice($number, $decimals = 2, $priceFormat = null) {
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

    $typeLabels = [
        'invoice' => 'Invoice',
        'payment' => 'Payment',
        'opening' => 'Opening Balance',
        'opening_payment' => 'Opening Balance Payment',
        'return' => 'Sale Return',
        'refund' => 'Refund',
        'service' => 'Service Job',
        'service_payment' => 'Service Payment',
    ];

    $periodText = ($fromDate || $toDate)
        ? trim(($fromDate ?: '…').'  to  '.($toDate ?: '…'))
        : 'All time';
@endphp
<!DOCTYPE html>
<html lang="{{ $pdfLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Account Statement - {{ $client['name'] ?? '' }}</title>
    <style>
        @page { size: A4; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9pt;
            color: #334155;
            line-height: 1.25;
            padding: 25px 25px 35px 25px;
            background-color: #ffffff;
        }
        table { width: 100%; border-collapse: collapse; }
        .label { font-size: 7.5pt; font-weight: bold; color: #94a3b8; text-transform: uppercase; margin-bottom: 1px; display: block; }

        .stmt-table thead tr { background-color: #f8fafc; }
        .stmt-table th { padding: 5px 8px; color: #475569; font-size: 8.5pt; font-weight: bold; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .stmt-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; line-height: 1.2; }
        .stmt-table tbody tr { page-break-inside: avoid; break-inside: avoid; }
        .stmt-table tbody tr.opening-row { background: #f8fafc; }

        .badge {
            display: inline-block;
            background: #eff6ff;
            color: #2563eb;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
        }

        .kpi-box {
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
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
                            <div style="font-size: 11pt; font-weight: bold; color: #0f172a;">{{ $setting['CompanyName'] ?? '' }}</div>
                            <div style="font-size: 8pt; color: #64748b;">{{ $setting['CompanyAdress'] ?? '' }}</div>
                            <div style="font-size: 8pt; color: #64748b;">{{ $setting['CompanyPhone'] ?? '' }} | {{ $setting['email'] ?? '' }}</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 40%; text-align: right; vertical-align: top;">
                <div style="font-size: 18pt; font-weight: 800; color: #0f172a; line-height: 1;">ACCOUNT STATEMENT</div>
                <div style="font-size: 9pt; font-weight: bold; color: #475569; margin-top: 4px;">Period: {{ $periodText }}</div>
                <div style="font-size: 8pt; color: #94a3b8; margin-top: 2px;">Generated: {{ now()->format('Y-m-d') }}</div>
            </td>
        </tr>
    </table>

    <div style="height: 1px; background: #f1f5f9; margin-bottom: 12px;"></div>

    <table style="margin-bottom: 15px;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                <span class="label">Customer</span>
                <div style="font-size: 10.5pt; font-weight: bold; color: #1e293b;">{{ $client['name'] ?? '-' }}</div>
                @if(!empty($client['code']))
                    <div style="font-size: 8pt; color: #94a3b8;">Code: {{ $client['code'] }}</div>
                @endif
                @if(!empty($client['adresse']))
                    <div style="font-size: 8.5pt; color: #64748b; margin-top: 2px;">{{ $client['adresse'] }}</div>
                @endif
                <div style="font-size: 8.5pt; color: #64748b;">
                    {{ $client['phone'] ?? '' }}{{ !empty($client['phone']) && !empty($client['email']) ? ' | ' : '' }}{{ $client['email'] ?? '' }}
                </div>
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table>
                    <tr>
                        <td style="width: 50%; padding-right: 6px;">
                            <div class="kpi-box">
                                <span class="label">Opening Balance</span>
                                <div style="font-size: 11pt; font-weight: bold; color: {{ (float)$opening_balance > 0 ? '#e11d48' : '#166534' }};">
                                    {{ $symbol }} {{ stmtFormatPrice($opening_balance, 2, $priceFormat) }}
                                </div>
                            </div>
                        </td>
                        <td style="width: 50%; padding-left: 6px;">
                            <div class="kpi-box">
                                <span class="label">Closing Balance</span>
                                <div style="font-size: 11pt; font-weight: bold; color: {{ (float)$closing_balance > 0 ? '#e11d48' : '#166534' }};">
                                    {{ $symbol }} {{ stmtFormatPrice($closing_balance, 2, $priceFormat) }}
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="stmt-table">
        <thead>
            <tr>
                <th style="width: 10%;">Date</th>
                <th style="width: 14%;">Type</th>
                <th style="width: 12%;">Ref</th>
                <th style="width: 28%;">Description</th>
                <th style="width: 12%; text-align: right;">Debit</th>
                <th style="width: 12%; text-align: right;">Credit</th>
                <th style="width: 12%; text-align: right;">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $entry)
                <tr class="{{ $entry['type'] === 'opening' ? 'opening-row' : '' }}">
                    <td style="white-space: nowrap; color: #64748b;">{{ $entry['date'] }}</td>
                    <td><span class="badge">{{ $typeLabels[$entry['type']] ?? ucfirst(str_replace('_',' ',$entry['type'])) }}</span></td>
                    <td style="color: #1e293b;">{{ $entry['ref'] }}</td>
                    <td style="color: #475569;">{{ $entry['description'] }}</td>
                    <td style="text-align: right; color: #e11d48; font-weight: 600;">{{ $entry['debit'] ? stmtFormatPrice($entry['debit'], 2, $priceFormat) : '—' }}</td>
                    <td style="text-align: right; color: #166534; font-weight: 600;">{{ $entry['credit'] ? stmtFormatPrice($entry['credit'], 2, $priceFormat) : '—' }}</td>
                    <td style="text-align: right; color: #0f172a; font-weight: bold;">{{ stmtFormatPrice($entry['balance'], 2, $priceFormat) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align: center; color: #94a3b8; padding: 20px 0;">No transactions in this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table style="margin-top: 15px;">
        <tr>
            <td style="width: 65%;"></td>
            <td style="width: 35%;">
                <table class="stmt-table" style="border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden;">
                    <tr>
                        <td style="font-weight: bold; color: #475569;">Closing Balance</td>
                        <td style="text-align: right; font-weight: bold; font-size: 10.5pt; color: {{ (float)$closing_balance > 0 ? '#e11d48' : '#166534' }};">
                            {{ $symbol }} {{ stmtFormatPrice($closing_balance, 2, $priceFormat) }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div style="margin-top: 25px; font-size: 7.5pt; color: #94a3b8; text-align: center;">
        This is a system-generated statement of account and does not require a signature.
    </div>

</body>
</html>
