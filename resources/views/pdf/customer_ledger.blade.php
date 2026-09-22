@php
    $pdfLocale = app()->getLocale();
    $isRtl = $pdfLocale === 'ar';
    $priceFormat = $settings->price_format ?? null;

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

    function pillPayment($status) {
        $s = strtolower(trim((string)$status));
        if (preg_match('/\b(unpaid|not\s*paid|overdue|failed|due)\b/', $s)) return 'status-pill unpaid-pill';
        if (preg_match('/\b(partial|partially\s*paid|part-?paid)\b/', $s))  return 'status-pill partial-pill';
        if (preg_match('/\b(paid|settled|paid\s*in\s*full)\b/', $s))        return 'status-pill paid-pill';
        return 'status-pill';
    }

    $salesGrandSum = $sales->sum('GrandTotal');
    $salesPaidSum  = $sales->sum('paid_amount');
    $salesDueSum   = $sales->sum(function($s){ return (float)($s->GrandTotal ?? 0) - (float)($s->paid_amount ?? 0); });

    $paymentsSum   = $payments->sum('montant');
    $quotesGrand   = $quotations->sum('GrandTotal');

    $retGrandSum   = $returns->sum('GrandTotal');
    $retPaidSum    = $returns->sum('paid_amount');
    $retDueSum     = $returns->sum(function($r){ return (float)($r->GrandTotal ?? 0) - (float)($r->paid_amount ?? 0); });

    $serviceJobs   = $serviceJobs ?? collect();
    $svcDueStatuses = class_exists('\App\Models\ServiceJob') ? \App\Models\ServiceJob::DUE_STATUSES : [];
    $svcGrandSum   = $serviceJobs->sum('total_amount');
    $svcPaidSum    = $serviceJobs->sum('paid_amount');
    $svcDueSum     = $serviceJobs->filter(function($j) use ($svcDueStatuses){ return in_array($j->status, $svcDueStatuses, true); })
                        ->sum(function($j){ return (float)($j->total_amount ?? 0) - (float)($j->paid_amount ?? 0); });

    $qsOpeningBalance = (float)($client->opening_balance ?? 0);
    $qsSalesGrand   = (float)($client->salesGrand ?? $salesGrandSum);
    $qsSalesPaid    = (float)($client->salesPaid  ?? $salesPaidSum);
    $qsSaleDue      = (float)($client->sale_due   ?? ($qsSalesGrand - $qsSalesPaid));

    $qsReturnsDue   = (float)($client->return_due ?? $retDueSum);
    $qsServiceDue   = (float)($client->service_due ?? $svcDueSum);
    $qsPaymentsTot  = (float)($client->paymentsTotal ?? $paymentsSum);
    $qsQuotesGrand  = (float)($client->quotationsTotal ?? $quotesGrand);

    $qsNetBalance   = isset($client->netBalance)
                      ? (float)$client->netBalance
                      : ($qsOpeningBalance + $qsSaleDue + $qsServiceDue - $qsReturnsDue);
@endphp

<!DOCTYPE html>
<html lang="{{ $pdfLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4; margin: 12mm 14mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'DejaVu Sans', sans-serif; }

        body { background-color: #ffffff; color: #334155; line-height: 1.45; padding: 15px; }

        .header-wrapper { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .logo-area { vertical-align: middle; }
        .title-area { text-align: right; vertical-align: middle; }
        .brand-name { font-size: 18pt; font-weight: bold; color: #4f46e5; }
        .report-tag { font-size: 7.5pt; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px; }

        .hr-line { width: 100%; height: 1px; background-color: #e2e8f0; margin-bottom: 18px; }

        .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .info-card {
            width: 48%; background: #fafafa; border: 1px solid #e2e8f0;
            border-radius: 8px; padding: 12px 14px; vertical-align: top;
        }
        .info-label { font-size: 7.5pt; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 6px; display: block; border-bottom: 1px solid #edf2f7; padding-bottom: 4px; }
        .info-title { font-size: 10pt; font-weight: 700; color: #0f172a; margin-bottom: 3px; }
        .info-detail { font-size: 8.5pt; color: #64748b; line-height: 1.5; }

        .kpi-container { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin-bottom: 12px; margin-left: -8px; }
        .kpi-card {
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: 8px; padding: 10px 12px; text-align: left; width: 25%; vertical-align: top;
        }
        .kpi-line { width: 20px; height: 3px; border-radius: 10px; margin-bottom: 6px; }
        .kpi-label { font-size: 6.5pt; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 2px; }
        .kpi-value { font-size: 11pt; font-weight: 800; color: #0f172a; }

        .bg-total { background: #6366f1; }
        .bg-paid { background: #10b981; }
        .bg-due { background: #ef4444; }
        .bg-return { background: #f59e0b; }
        .bg-info { background: #0ea5e9; }

        .table-title { font-size: 10pt; font-weight: 800; color: #0f172a; margin: 20px 0 8px; padding-left: 2px; }

        .modern-table { width: 100%; border-collapse: collapse; background: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; margin-bottom: 10px; }
        .modern-table th {
            background: #f8fafc; padding: 10px 12px; text-align: left;
            font-size: 7.5pt; color: #627d98; text-transform: uppercase; font-weight: 700; border-bottom: 1px solid #e2e8f0;
        }
        .modern-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-size: 8.5pt; color: #334155; }
        .modern-table tr:nth-child(even) { background-color: #fcfdfe; }

        .modern-table tfoot td {
            background: #f8fafc; padding: 10px 12px; font-size: 8.5pt; font-weight: bold; color: #1f2937; border-top: 1px solid #e2e8f0;
        }

        .ref-badge { font-weight: 700; color: #4f46e5; }
        .status-pill { padding: 2px 8px; border-radius: 4px; font-size: 7pt; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .paid-pill { background: #e6f4ea; color: #137333; }
        .partial-pill { background: #fef7e0; color: #b06000; }
        .unpaid-pill { background: #fce8e6; color: #c5221f; }

        .footer { text-align: center; margin-top: 30px; border-top: 1px solid #f1f5f9; padding-top: 15px; line-height: 1.6; }
        .footer-notice { font-size: 8.5pt; color: #475569; font-weight: bold; }
        .footer-date { font-size: 7.5pt; color: #94a3b8; }
    </style>
</head>
<body>

    <!-- HEADER -->
    <table class="header-wrapper">
        <tr>
            <td class="logo-area">
                @if(!empty($settings->logo))
                    <img src="data:image/png;base64,{{ base64_encode(@file_get_contents(public_path('images/'.$settings->logo))) }}" style="max-height: 45px;">
                @else
                    <div class="brand-name">{{ $settings->CompanyName ?? 'Company' }}</div>
                @endif
            </td>
            <td class="title-area">
                <div class="report-tag">{{ __('pdf.customer_ledger') }}</div>
                <div style="font-size: 14pt; font-weight: 800; color: #0f172a;">{{ $client->name }}</div>
            </td>
        </tr>
    </table>

    <div class="hr-line"></div>

    <!-- CLIENT & BUSINESS INFO -->
    <table class="info-grid">
        <tr>
            <td class="info-card">
                <span class="info-label">From Business</span>
                <div class="info-title">{{ $settings->CompanyName ?? '-' }}</div>
                <div class="info-detail">
                    {{ $settings->CompanyAdress ?? '-' }}<br>
                    @if(!empty($settings->vat_number)) VAT/BIN: {{ $settings->vat_number }}<br> @endif
                    Phone: {{ $settings->CompanyPhone ?? '-' }}<br>
                    Email: {{ $settings->email ?? '-' }}
                    @if(!empty($settings->website)) <br>Website: {{ $settings->website }} @endif
                </div>
            </td>
            <td style="width: 4%;"></td>
            <td class="info-card">
                <span class="info-label">Customer Details</span>
                <div class="info-title">{{ $client->name }}</div>
                <div class="info-detail">
                    Address: {{ $client->address ?? $client->adresse ?? '-' }}<br>
                    Phone: {{ $client->phone ?? '-' }}
                    @if(!empty($client->email)) <br>Email: {{ $client->email }} @endif
                    <br>City: {{ $client->city ?? '-' }}, Country: {{ $client->country ?? '-' }}
                </div>
            </td>
        </tr>
    </table>

    <!-- QUICK STATS / KPI ROW 1 -->
    <table class="kpi-container">
        <tr>
            <td class="kpi-card">
                <div class="kpi-line bg-info"></div>
                <div class="kpi-label">{{ __('pdf.opening_balance') }}</div>
                <div class="kpi-value">{{ formatPrice($qsOpeningBalance, 2, $priceFormat) }}</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-line bg-total"></div>
                <div class="kpi-label">Sales Grand</div>
                <div class="kpi-value">{{ formatPrice($qsSalesGrand, 2, $priceFormat) }}</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-line bg-paid"></div>
                <div class="kpi-label">Sales Paid</div>
                <div class="kpi-value">{{ formatPrice($qsSalesPaid, 2, $priceFormat) }}</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-line bg-due"></div>
                <div class="kpi-label">Sales Due</div>
                <div class="kpi-value" style="color: #ef4444;">{{ formatPrice($qsSaleDue, 2, $priceFormat) }}</div>
            </td>
        </tr>
    </table>

    <!-- QUICK STATS / KPI ROW 2 -->
    <table class="kpi-container">
        <tr>
            <td class="kpi-card">
                <div class="kpi-line bg-due"></div>
                <div class="kpi-label">Service Due</div>
                <div class="kpi-value" style="color: #ef4444;">{{ formatPrice($qsServiceDue, 2, $priceFormat) }}</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-line bg-return"></div>
                <div class="kpi-label">Returns Due</div>
                <div class="kpi-value" style="color: #f59e0b;">{{ formatPrice($qsReturnsDue, 2, $priceFormat) }}</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-line bg-paid"></div>
                <div class="kpi-label">Payments Total</div>
                <div class="kpi-value">{{ formatPrice($qsPaymentsTot, 2, $priceFormat) }}</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-line bg-total"></div>
                <div class="kpi-label">{{ __('pdf.net_balance') }}</div>
                <div class="kpi-value" style="color: {{ $qsNetBalance >= 0 ? '#ef4444' : '#10b981' }};">
                    {{ formatPrice($qsNetBalance, 2, $priceFormat) }}
                </div>
            </td>
        </tr>
    </table>

    <!-- SALES -->
    <div class="table-title">{{ __('pdf.sales') }}</div>
    <table class="modern-table">
        <thead>
            <tr>
                <th>{{ __('pdf.date') }}</th>
                <th>{{ __('pdf.ref') }}</th>
                <th>{{ __('pdf.warehouse') }}</th>
                <th style="text-align: right;">{{ __('pdf.grand_total') }}</th>
                <th style="text-align: right;">{{ __('pdf.paid') }}</th>
                <th style="text-align: right;">{{ __('pdf.due') }}</th>
                <th style="text-align: center;">{{ __('pdf.status') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sales as $s)
            <tr>
                <td style="color: #64748b; font-size: 8pt;">{{ \Carbon\Carbon::parse($s->date)->format('d M, Y') }}</td>
                <td class="ref-badge">{{ $s->Ref }}</td>
                <td>{{ optional($s->warehouse)->name }}</td>
                <td style="text-align: right; font-weight: bold; color: #475569;">{{ formatPrice($s->GrandTotal, 2, $priceFormat) }}</td>
                <td style="text-align: right; font-weight: bold; color: #10b981;">{{ formatPrice($s->paid_amount, 2, $priceFormat) }}</td>
                <td style="text-align: right; font-weight: bold; color: #ef4444;">{{ formatPrice(($s->GrandTotal - $s->paid_amount), 2, $priceFormat) }}</td>
                <td style="text-align: center;"><span class="{{ pillPayment($s->payment_statut) }}">{{ $s->payment_statut }}</span></td>
            </tr>
            @empty
            <tr><td colspan="7" style="text-align: center;">No sales found.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="text-align: right;">{{ __('pdf.totals') }}</td>
                <td style="text-align: right;">{{ formatPrice($salesGrandSum, 2, $priceFormat) }}</td>
                <td style="text-align: right; color: #10b981;">{{ formatPrice($salesPaidSum, 2, $priceFormat) }}</td>
                <td style="text-align: right; color: #ef4444;">{{ formatPrice($salesDueSum, 2, $priceFormat) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <!-- SERVICE JOBS -->
    @if($serviceJobs->count())
    <div class="table-title">{{ __('pdf.service') }}</div>
    <table class="modern-table">
        <thead>
            <tr>
                <th>{{ __('pdf.date') }}</th>
                <th>{{ __('pdf.ref') }}</th>
                <th>{{ __('pdf.service') }}</th>
                <th style="text-align: right;">{{ __('pdf.grand_total') }}</th>
                <th style="text-align: right;">{{ __('pdf.paid') }}</th>
                <th style="text-align: right;">{{ __('pdf.due') }}</th>
                <th style="text-align: center;">{{ __('pdf.status') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($serviceJobs as $j)
            @php
                $jDue = (float)($j->total_amount ?? 0) - (float)($j->paid_amount ?? 0);
                $jDate = $j->created_at ? \Carbon\Carbon::parse($j->created_at)->format('d M, Y') : '-';
            @endphp
            <tr>
                <td style="color: #64748b; font-size: 8pt;">{{ $jDate }}</td>
                <td class="ref-badge">{{ $j->Ref }}</td>
                <td>{{ $j->service_item ?? '-' }}</td>
                <td style="text-align: right; font-weight: bold; color: #475569;">{{ formatPrice($j->total_amount, 2, $priceFormat) }}</td>
                <td style="text-align: right; font-weight: bold; color: #10b981;">{{ formatPrice($j->paid_amount, 2, $priceFormat) }}</td>
                <td style="text-align: right; font-weight: bold; color: #ef4444;">{{ formatPrice($jDue, 2, $priceFormat) }}</td>
                <td style="text-align: center;"><span class="{{ pillPayment($j->payment_status) }}">{{ $j->payment_status }}</span></td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="text-align: right;">{{ __('pdf.totals') }}</td>
                <td style="text-align: right;">{{ formatPrice($svcGrandSum, 2, $priceFormat) }}</td>
                <td style="text-align: right; color: #10b981;">{{ formatPrice($svcPaidSum, 2, $priceFormat) }}</td>
                <td style="text-align: right; color: #ef4444;">{{ formatPrice($svcDueSum, 2, $priceFormat) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    @endif

    <!-- PAYMENTS -->
    <div class="table-title">{{ __('pdf.payments') }}</div>
    <table class="modern-table">
        <thead>
            <tr>
                <th>{{ __('pdf.date') }}</th>
                <th>{{ __('pdf.payment_ref') }}</th>
                <th>{{ __('pdf.type') }}</th>
                <th>{{ __('pdf.sale_ref') }}</th>
                <th>{{ __('pdf.method') }}</th>
                <th style="text-align: right;">{{ __('pdf.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payments as $p)
            <tr>
                <td style="color: #64748b; font-size: 8pt;">{{ \Carbon\Carbon::parse($p->date)->format('d M, Y') }}</td>
                <td class="ref-badge">{{ $p->Ref }}</td>
                <td>
                    @if(isset($p->payment_type) && $p->payment_type === 'opening_balance')
                        <span class="status-pill partial-pill">{{ __('pdf.opening_balance') }}</span>
                    @elseif(isset($p->payment_type) && $p->payment_type === 'service')
                        <span class="status-pill partial-pill">{{ __('pdf.service') }}</span>
                    @else
                        <span class="status-pill paid-pill">{{ __('pdf.sale') }}</span>
                    @endif
                </td>
                <td>{{ $p->Sale_Ref ?? '-' }}</td>
                <td>{{ $p->payment_method }}</td>
                <td style="text-align: right; font-weight: bold; color: #10b981;">{{ formatPrice($p->montant, 2, $priceFormat) }}</td>
            </tr>
            @empty
            <tr><td colspan="6" style="text-align: center;">No payments found.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" style="text-align: right;">Total Payments</td>
                <td style="text-align: right; color: #10b981;">{{ formatPrice($paymentsSum, 2, $priceFormat) }}</td>
            </tr>
        </tfoot>
    </table>

    <!-- RETURNS -->
    @if($returns->count())
    <div class="table-title">{{ __('pdf.returns') }}</div>
    <table class="modern-table">
        <thead>
            <tr>
                <th>{{ __('pdf.ref') }}</th>
                <th>{{ __('pdf.sale_ref') }}</th>
                <th>{{ __('pdf.warehouse') }}</th>
                <th style="text-align: right;">{{ __('pdf.grand_total') }}</th>
                <th style="text-align: right;">{{ __('pdf.paid') }}</th>
                <th style="text-align: right;">{{ __('pdf.due') }}</th>
                <th style="text-align: center;">{{ __('pdf.payment_status') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($returns as $r)
            <tr>
                <td class="ref-badge">{{ $r->Ref }}</td>
                <td>{{ optional($r->sale)->Ref ?? '---' }}</td>
                <td style="text-align: left;">{{ optional($r->warehouse)->name }}</td>
                <td style="text-align: right; font-weight: bold; color: #475569;">{{ formatPrice($r->GrandTotal, 2, $priceFormat) }}</td>
                <td style="text-align: right; font-weight: bold; color: #10b981;">{{ formatPrice($r->paid_amount, 2, $priceFormat) }}</td>
                <td style="text-align: right; font-weight: bold; color: #ef4444;">{{ formatPrice(($r->GrandTotal - $r->paid_amount), 2, $priceFormat) }}</td>
                <td style="text-align: center;"><span class="{{ pillPayment($r->payment_statut) }}">{{ $r->payment_statut }}</span></td>
            </tr>
            @empty
            <tr><td colspan="7" style="text-align: center;">No returns found.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="text-align: right;">{{ __('pdf.totals') }}</td>
                <td style="text-align: right;">{{ formatPrice($retGrandSum, 2, $priceFormat) }}</td>
                <td style="text-align: right; color: #10b981;">{{ formatPrice($retPaidSum, 2, $priceFormat) }}</td>
                <td style="text-align: right; color: #ef4444;">{{ formatPrice($retDueSum, 2, $priceFormat) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    @endif

    <div class="footer">
        <div class="footer-notice">This is a system generated document.</div>
        <div class="footer-date">Generated on {{ date('d M, Y, h:i A') }}</div>
    </div>

</body>
</html>
