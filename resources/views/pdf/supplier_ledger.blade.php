@php
  $pdfLocale = app()->getLocale(); $isRtl = $pdfLocale === 'ar'; $priceFormat = $settings->price_format ?? null;
  if (!function_exists('supplierLedgerPrice')) { function supplierLedgerPrice($n,$d=2,$f=null){
    $n=(float)$n; if($f==='dot_comma') return number_format($n,$d,',','.');
    if($f==='space_comma') return number_format($n,$d,',',' '); return number_format($n,$d,'.',',');
  }}
  if (!function_exists('supplierLedgerPill')) { function supplierLedgerPill($status){
    $s=strtolower(trim((string)$status));
    if(preg_match('/\b(unpaid|not\s*paid|overdue|failed|due)\b/',$s)) return 'status-pill unpaid-pill';
    if(preg_match('/\b(partial|partially\s*paid|part-?paid)\b/',$s)) return 'status-pill partial-pill';
    if(preg_match('/\b(paid|settled|paid\s*in\s*full)\b/',$s)) return 'status-pill paid-pill';
    return 'status-pill';
  }}
  $purchaseGrand=(float)($provider->purchasesGrand ?? $purchases->where('statut','received')->sum('GrandTotal'));
  $purchasePaid=(float)($provider->purchasesPaid ?? $purchases->where('statut','received')->sum('paid_amount'));
  $purchaseDue=(float)($provider->purchaseDue ?? ($purchaseGrand-$purchasePaid));
  $returnGrand=(float)($provider->returnsGrand ?? $returns->sum('GrandTotal'));
  $returnPaid=(float)($provider->returnsPaid ?? $returns->sum('paid_amount'));
  $returnDue=(float)($provider->returnDue ?? ($returnGrand-$returnPaid));
  $paymentsTotal=(float)($provider->paymentsTotal ?? $payments->sum('montant'));
  $refundsTotal=(float)($provider->returnRefundsTotal ?? $returnRefunds->sum('montant'));
  $openingBalance=(float)($provider->opening_balance ?? 0);
  $netBalance=(float)($provider->netBalance ?? ($openingBalance+$purchaseDue-$returnDue));
  $tableGrand=(float)$purchases->sum('GrandTotal'); $tablePaid=(float)$purchases->sum('paid_amount'); $tableDue=$tableGrand-$tablePaid;
@endphp
<!DOCTYPE html>
<html lang="{{ $pdfLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title>Supplier Ledger</title>
<style>
@page{size:A4;margin:12mm 14mm}*{margin:0;padding:0;box-sizing:border-box;font-family:'DejaVu Sans',sans-serif}body{background:#fff;color:#334155;line-height:1.45;padding:15px}
.header-wrapper{width:100%;border-collapse:collapse;margin-bottom:16px}.logo-area{vertical-align:middle}.title-area{text-align:right;vertical-align:middle}.brand-name{font-size:18pt;font-weight:bold;color:#4f46e5}.report-tag{font-size:7.5pt;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:2px}.hr-line{width:100%;height:1px;background:#e2e8f0;margin-bottom:18px}
.info-grid{width:100%;border-collapse:collapse;margin-bottom:20px}.info-card{width:48%;background:#fafafa;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;vertical-align:top}.info-label{font-size:7.5pt;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px;display:block;border-bottom:1px solid #edf2f7;padding-bottom:4px}.info-title{font-size:10pt;font-weight:700;color:#0f172a;margin-bottom:3px}.info-detail{font-size:8.5pt;color:#64748b;line-height:1.5}
.kpi-container{width:100%;border-collapse:separate;border-spacing:8px 0;margin:0 0 12px -8px}.kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;text-align:left;width:25%;vertical-align:top}.kpi-line{width:20px;height:3px;border-radius:10px;margin-bottom:6px}.kpi-label{font-size:6.5pt;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px}.kpi-value{font-size:11pt;font-weight:800;color:#0f172a}.bg-total{background:#6366f1}.bg-paid{background:#10b981}.bg-due{background:#ef4444}.bg-return{background:#f59e0b}.bg-info{background:#0ea5e9}
.table-title{font-size:10pt;font-weight:800;color:#0f172a;margin:20px 0 8px;padding-left:2px}.modern-table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;margin-bottom:10px}.modern-table th{background:#f8fafc;padding:10px 12px;text-align:left;font-size:7.5pt;color:#627d98;text-transform:uppercase;font-weight:700;border-bottom:1px solid #e2e8f0}.modern-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;font-size:8.5pt;color:#334155}.modern-table tr:nth-child(even){background:#fcfdfe}.modern-table tfoot td{background:#f8fafc;padding:10px 12px;font-size:8.5pt;font-weight:bold;color:#1f2937;border-top:1px solid #e2e8f0}.ref-badge{font-weight:700;color:#4f46e5}.status-pill{padding:2px 8px;border-radius:4px;font-size:7pt;font-weight:700;text-transform:uppercase;display:inline-block}.paid-pill{background:#e6f4ea;color:#137333}.partial-pill{background:#fef7e0;color:#b06000}.unpaid-pill{background:#fce8e6;color:#c5221f}.footer{text-align:center;margin-top:30px;border-top:1px solid #f1f5f9;padding-top:15px;line-height:1.6}.footer-notice{font-size:8.5pt;color:#475569;font-weight:bold}.footer-date{font-size:7.5pt;color:#94a3b8}
</style></head><body>
<table class="header-wrapper"><tr><td class="logo-area">
@if(!empty($settings->logo))<img src="data:image/png;base64,{{ base64_encode(@file_get_contents(public_path('images/'.$settings->logo))) }}" style="max-height:45px">@else<div class="brand-name">{{ $settings->CompanyName ?? 'Company' }}</div>@endif
</td><td class="title-area"><div class="report-tag">Supplier Ledger</div><div style="font-size:14pt;font-weight:800;color:#0f172a">{{ $provider->name }}</div></td></tr></table>
<div class="hr-line"></div>
<table class="info-grid"><tr><td class="info-card"><span class="info-label">From Business</span><div class="info-title">{{ $settings->CompanyName ?? '-' }}</div><div class="info-detail">
{{ $settings->CompanyAdress ?? '-' }}<br>@if(!empty($settings->vat_number))VAT/BIN: {{ $settings->vat_number }}<br>@endif
Phone: {{ $settings->CompanyPhone ?? '-' }}<br>Email: {{ $settings->email ?? '-' }}@if(!empty($settings->website))<br>Website: {{ $settings->website }}@endif
</div></td><td style="width:4%"></td><td class="info-card"><span class="info-label">Supplier Details</span><div class="info-title">{{ $provider->name }}</div><div class="info-detail">
Address: {{ $provider->adresse ?? '-' }}<br>Code: {{ $provider->code ?? '-' }} &bull; Phone: {{ $provider->phone ?? '-' }}@if(!empty($provider->email))<br>Email: {{ $provider->email }}@endif<br>City: {{ $provider->city ?? '-' }}, Country: {{ $provider->country ?? '-' }}
</div></td></tr></table>

<table class="kpi-container"><tr>
<td class="kpi-card"><div class="kpi-line bg-info"></div><div class="kpi-label">Opening Balance</div><div class="kpi-value">{{ supplierLedgerPrice($openingBalance,2,$priceFormat) }}</div></td>
<td class="kpi-card"><div class="kpi-line bg-total"></div><div class="kpi-label">Purchases Grand</div><div class="kpi-value">{{ supplierLedgerPrice($purchaseGrand,2,$priceFormat) }}</div></td>
<td class="kpi-card"><div class="kpi-line bg-paid"></div><div class="kpi-label">Purchases Paid</div><div class="kpi-value">{{ supplierLedgerPrice($purchasePaid,2,$priceFormat) }}</div></td>
<td class="kpi-card"><div class="kpi-line bg-due"></div><div class="kpi-label">Purchases Due</div><div class="kpi-value" style="color:#ef4444">{{ supplierLedgerPrice($purchaseDue,2,$priceFormat) }}</div></td>
</tr></table><table class="kpi-container"><tr>
<td class="kpi-card"><div class="kpi-line bg-return"></div><div class="kpi-label">Returns Grand</div><div class="kpi-value">{{ supplierLedgerPrice($returnGrand,2,$priceFormat) }}</div></td>
<td class="kpi-card"><div class="kpi-line bg-return"></div><div class="kpi-label">Returns Due</div><div class="kpi-value" style="color:#f59e0b">{{ supplierLedgerPrice($returnDue,2,$priceFormat) }}</div></td>
<td class="kpi-card"><div class="kpi-line bg-paid"></div><div class="kpi-label">Payments Total</div><div class="kpi-value">{{ supplierLedgerPrice($paymentsTotal,2,$priceFormat) }}</div></td>
<td class="kpi-card"><div class="kpi-line bg-total"></div><div class="kpi-label">Net Balance</div><div class="kpi-value" style="color:{{ $netBalance>=0?'#ef4444':'#10b981' }}">{{ supplierLedgerPrice($netBalance,2,$priceFormat) }}</div></td>
</tr></table>

<div class="table-title">Purchases</div><table class="modern-table"><thead><tr><th>Date</th><th>Ref</th><th>Warehouse</th><th style="text-align:right">Grand Total</th><th style="text-align:right">Paid</th><th style="text-align:right">Due</th><th style="text-align:center">Status</th></tr></thead><tbody>
@forelse($purchases as $p)<tr><td style="color:#64748b;font-size:8pt">{{ \Carbon\Carbon::parse($p->date)->format('d M, Y') }}</td><td class="ref-badge">{{ $p->Ref }}</td><td>{{ optional($p->warehouse)->name }}</td><td style="text-align:right;font-weight:bold;color:#475569">{{ supplierLedgerPrice($p->GrandTotal,2,$priceFormat) }}</td><td style="text-align:right;font-weight:bold;color:#10b981">{{ supplierLedgerPrice($p->paid_amount,2,$priceFormat) }}</td><td style="text-align:right;font-weight:bold;color:#ef4444">{{ supplierLedgerPrice($p->GrandTotal-$p->paid_amount,2,$priceFormat) }}</td><td style="text-align:center"><span class="{{ supplierLedgerPill($p->payment_statut) }}">{{ $p->payment_statut }}</span></td></tr>@empty<tr><td colspan="7" style="text-align:center">No purchases found.</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="3" style="text-align:right">Totals</td><td style="text-align:right">{{ supplierLedgerPrice($tableGrand,2,$priceFormat) }}</td><td style="text-align:right;color:#10b981">{{ supplierLedgerPrice($tablePaid,2,$priceFormat) }}</td><td style="text-align:right;color:#ef4444">{{ supplierLedgerPrice($tableDue,2,$priceFormat) }}</td><td></td></tr></tfoot></table>

<div class="table-title">Payments</div><table class="modern-table"><thead><tr><th>Date</th><th>Payment Ref</th><th>Type</th><th>Purchase Ref</th><th>Method</th><th style="text-align:right">Amount</th></tr></thead><tbody>
@forelse($payments as $p)<tr><td style="color:#64748b;font-size:8pt">{{ \Carbon\Carbon::parse($p->date)->format('d M, Y') }}</td><td class="ref-badge">{{ $p->Ref }}</td><td><span class="status-pill {{ $p->payment_type==='opening_balance'?'partial-pill':'paid-pill' }}">{{ $p->payment_type==='opening_balance'?'Opening Balance':'Purchase' }}</span></td><td>{{ $p->purchase_ref ?? '-' }}</td><td>{{ $p->payment_method ?? '-' }}</td><td style="text-align:right;font-weight:bold;color:#10b981">{{ supplierLedgerPrice($p->montant,2,$priceFormat) }}</td></tr>@empty<tr><td colspan="6" style="text-align:center">No payments found.</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="5" style="text-align:right">Total Payments</td><td style="text-align:right;color:#10b981">{{ supplierLedgerPrice($paymentsTotal,2,$priceFormat) }}</td></tr></tfoot></table>

@if($returns->count())<div class="table-title">Purchase Returns</div><table class="modern-table"><thead><tr><th>Ref</th><th>Purchase Ref</th><th>Warehouse</th><th style="text-align:right">Grand Total</th><th style="text-align:right">Paid</th><th style="text-align:right">Due</th><th style="text-align:center">Status</th></tr></thead><tbody>
@foreach($returns as $r)<tr><td class="ref-badge">{{ $r->Ref }}</td><td>{{ optional($r->purchase)->Ref ?? '-' }}</td><td>{{ optional($r->warehouse)->name }}</td><td style="text-align:right;font-weight:bold;color:#475569">{{ supplierLedgerPrice($r->GrandTotal,2,$priceFormat) }}</td><td style="text-align:right;font-weight:bold;color:#10b981">{{ supplierLedgerPrice($r->paid_amount,2,$priceFormat) }}</td><td style="text-align:right;font-weight:bold;color:#ef4444">{{ supplierLedgerPrice($r->GrandTotal-$r->paid_amount,2,$priceFormat) }}</td><td style="text-align:center"><span class="{{ supplierLedgerPill($r->payment_statut) }}">{{ $r->payment_statut }}</span></td></tr>@endforeach
</tbody><tfoot><tr><td colspan="3" style="text-align:right">Totals</td><td style="text-align:right">{{ supplierLedgerPrice($returnGrand,2,$priceFormat) }}</td><td style="text-align:right;color:#10b981">{{ supplierLedgerPrice($returnPaid,2,$priceFormat) }}</td><td style="text-align:right;color:#ef4444">{{ supplierLedgerPrice($returnDue,2,$priceFormat) }}</td><td></td></tr></tfoot></table>@endif

@if($returnRefunds->count())<div class="table-title">Return Refunds</div><table class="modern-table"><thead><tr><th>Date</th><th>Payment Ref</th><th>Return Ref</th><th>Method</th><th style="text-align:right">Amount</th></tr></thead><tbody>
@foreach($returnRefunds as $r)<tr><td style="color:#64748b;font-size:8pt">{{ \Carbon\Carbon::parse($r->date)->format('d M, Y') }}</td><td class="ref-badge">{{ $r->Ref }}</td><td>{{ $r->return_ref ?? '-' }}</td><td>{{ $r->payment_method ?? '-' }}</td><td style="text-align:right;font-weight:bold;color:#10b981">{{ supplierLedgerPrice($r->montant,2,$priceFormat) }}</td></tr>@endforeach
</tbody><tfoot><tr><td colspan="4" style="text-align:right">Total Return Refunds</td><td style="text-align:right;color:#10b981">{{ supplierLedgerPrice($refundsTotal,2,$priceFormat) }}</td></tr></tfoot></table>@endif

<div class="footer"><div class="footer-notice">This is a system generated document.</div><div class="footer-date">Generated on {{ date('d M, Y, h:i A') }}</div></div>
</body></html>
