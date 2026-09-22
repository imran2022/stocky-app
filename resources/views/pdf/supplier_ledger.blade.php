@php
  $pdfLocale = app()->getLocale();
  $isRtl = $pdfLocale === 'ar';
  $priceFormat = $settings->price_format ?? null;

  if (!function_exists('supplierLedgerPrice')) {
    function supplierLedgerPrice($number, $decimals = 2, $priceFormat = null) {
      $number = (float) $number;
      if ($priceFormat === 'dot_comma') return number_format($number, $decimals, ',', '.');
      if ($priceFormat === 'space_comma') return number_format($number, $decimals, ',', ' ');
      return number_format($number, $decimals, '.', ',');
    }
  }
  if (!function_exists('supplierLedgerStatus')) {
    function supplierLedgerStatus($status) {
      $status = strtolower(trim((string) $status));
      if (preg_match('/\b(received|completed|approved|closed)\b/', $status)) return 'pill success';
      if (preg_match('/\b(pending|ordered|awaiting|on hold)\b/', $status)) return 'pill warn';
      if (preg_match('/\b(canceled|cancelled|rejected|void)\b/', $status)) return 'pill danger';
      return 'pill';
    }
  }
  if (!function_exists('supplierLedgerPaymentStatus')) {
    function supplierLedgerPaymentStatus($status) {
      $status = strtolower(trim((string) $status));
      if (preg_match('/\b(unpaid|not\s*paid|overdue|failed|due)\b/', $status)) return 'pill danger';
      if (preg_match('/\b(partial|partially\s*paid|part-?paid)\b/', $status)) return 'pill warn';
      if (preg_match('/\b(paid|settled|paid\s*in\s*full)\b/', $status)) return 'pill success';
      return 'pill';
    }
  }
  if (!function_exists('supplierLedgerDate')) {
    function supplierLedgerDate($date, $settings) {
      if (!$date) return '-';
      $dateFormat = $settings->date_format ?? 'YYYY-MM-DD';
      $phpFormat = str_replace(['YYYY', 'MM', 'DD'], ['Y', 'm', 'd'], $dateFormat);
      return \Carbon\Carbon::parse($date)->format($phpFormat);
    }
  }

  $purchaseGrand = (float)($provider->purchasesGrand ?? $purchases->sum('GrandTotal'));
  $purchasePaid = (float)($provider->purchasesPaid ?? $purchases->sum('paid_amount'));
  $purchaseDue = (float)($provider->purchaseDue ?? ($purchaseGrand - $purchasePaid));
  $returnGrand = (float)($provider->returnsGrand ?? $returns->sum('GrandTotal'));
  $returnPaid = (float)($provider->returnsPaid ?? $returns->sum('paid_amount'));
  $returnDue = (float)($provider->returnDue ?? ($returnGrand - $returnPaid));
  $paymentsTotal = (float)($provider->paymentsTotal ?? $payments->sum('montant'));
  $refundsTotal = (float)($provider->returnRefundsTotal ?? $returnRefunds->sum('montant'));
  $openingBalance = (float)($provider->opening_balance ?? 0);
  $netBalance = (float)($provider->netBalance ?? ($openingBalance + $purchaseDue - $returnDue));
  $tablePurchaseGrand = (float)$purchases->sum('GrandTotal');
  $tablePurchasePaid = (float)$purchases->sum('paid_amount');
  $tablePurchaseDue = $tablePurchaseGrand - $tablePurchasePaid;
@endphp
<!doctype html>
<html lang="{{ $pdfLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
  <meta charset="utf-8">
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <title>Supplier Ledger</title>
  <style>
    @page { margin: 22px; }
    body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
    .wrap, tbody tr { page-break-inside: avoid; }
    .header { padding: 12px 14px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f8fafc; margin-bottom: 14px; }
    .h-top, .party-col { display: table; width: 100%; }
    .h-left, .h-right, .party-left, .party-right { display: table-cell; vertical-align: top; }
    .h-right, .party-right { text-align: right; }
    .title { font-size: 20px; font-weight: 700; color: #111827; }
    .muted { color: #6b7280; }
    .party-box { margin-top: 8px; padding: 10px 12px; border: 1px dashed #d1d5db; border-radius: 8px; background: #fff; }
    .stats { margin: 10px 0 14px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px; background: #fff; }
    .kpi-grid { display: table; width: 100%; border-spacing: 8px 6px; }
    .kpi { display: table-cell; width: 33%; border: 1px solid #eef2f7; border-radius: 10px; background: #f9fafb; padding: 10px; }
    .kpi .label { font-size: 11px; color: #6b7280; }
    .kpi .value { font-size: 16px; font-weight: 800; color: #111827; }
    .danger { color: #dc2626 !important; }
    .warning { color: #d97706 !important; }
    .success { color: #16a34a !important; }
    h3 { margin: 18px 0 8px; font-size: 14px; color: #0f172a; border-left: 4px solid #3b82f6; padding-left: 8px; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { border: 1px solid #e5e7eb; padding: 6px; vertical-align: middle; word-wrap: break-word; }
    thead th { font-size: 12px; text-align: left; background: #f3f4f6; color: #111827; }
    tbody tr:nth-child(odd) { background: #fafafa; }
    .right { text-align: right; }
    tfoot td { font-weight: 700; background: #f8fafc; }
    .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; border: 1px solid #e5e7eb; color: #374151; background: #f9fafb; }
    .pill.success { border-color: #bae6fd; color: #065f46; background: #ecfdf5; }
    .pill.warn { border-color: #fde68a; color: #92400e; background: #fffbeb; }
    .pill.info { border-color: #bfdbfe; color: #1e3a8a; background: #eff6ff; }
    .pill.danger { border-color: #fecaca; color: #7f1d1d; background: #fef2f2; }
  </style>
</head>
<body class="{{ $isRtl ? 'rtl' : '' }}">
  <div class="header wrap">
    <div class="h-top">
      <div class="h-left">
        <div class="title">Supplier Ledger</div>
        <div class="muted">Generated at: {{ now()->format('Y-m-d H:i') }}</div>
      </div>
      <div class="h-right"></div>
    </div>
    <div class="party-box">
      <div class="party-col">
        <div class="party-left">
          <strong>{{ $provider->name }}</strong><br>
          Code: {{ $provider->code ?? '-' }}<br>
          City: {{ $provider->city ?? '-' }}, Country: {{ $provider->country ?? '-' }}
        </div>
        <div class="party-right">
          Email: {{ $provider->email ?? '-' }}<br>
          Phone: {{ $provider->phone ?? '-' }}<br>
          Tax #: {{ $provider->tax_number ?? '-' }}
        </div>
      </div>
    </div>
  </div>

  <div class="stats wrap">
    <div class="kpi-grid">
      <div class="kpi"><div class="label">Opening Balance</div><div class="value {{ $openingBalance > 0 ? 'danger' : '' }}">{{ supplierLedgerPrice($openingBalance, 2, $priceFormat) }}</div></div>
      <div class="kpi"><div class="label">Purchases (Grand)</div><div class="value">{{ supplierLedgerPrice($purchaseGrand, 2, $priceFormat) }}</div></div>
      <div class="kpi"><div class="label">Purchases (Paid)</div><div class="value">{{ supplierLedgerPrice($purchasePaid, 2, $priceFormat) }}</div></div>
    </div>
    <div class="kpi-grid">
      <div class="kpi"><div class="label">Purchases (Due)</div><div class="value danger">{{ supplierLedgerPrice($purchaseDue, 2, $priceFormat) }}</div></div>
      <div class="kpi"><div class="label">Returns (Grand)</div><div class="value">{{ supplierLedgerPrice($returnGrand, 2, $priceFormat) }}</div></div>
      <div class="kpi"><div class="label">Returns (Due)</div><div class="value warning">{{ supplierLedgerPrice($returnDue, 2, $priceFormat) }}</div></div>
    </div>
    <div class="kpi-grid">
      <div class="kpi"><div class="label">Payments (Total)</div><div class="value">{{ supplierLedgerPrice($paymentsTotal, 2, $priceFormat) }}</div></div>
      <div class="kpi"><div class="label">Return Refunds</div><div class="value">{{ supplierLedgerPrice($refundsTotal, 2, $priceFormat) }}</div></div>
      <div class="kpi"><div class="label">Net Balance</div><div class="value {{ $netBalance >= 0 ? 'danger' : 'success' }}">{{ supplierLedgerPrice($netBalance, 2, $priceFormat) }}</div></div>
    </div>
  </div>

  @if($openingBalance != 0)
  <h3>Opening Balance (Previous Dues)</h3>
  <table>
    <thead><tr><th style="width:20%">Type</th><th style="width:25%">Description</th><th class="right" style="width:20%">Amount</th><th style="width:35%">Notes</th></tr></thead>
    <tbody><tr><td>Opening Balance</td><td>Previous Dues (Before System Start)</td><td class="right {{ $openingBalance > 0 ? 'danger' : '' }}">{{ supplierLedgerPrice($openingBalance, 2, $priceFormat) }}</td><td>Balance carried forward</td></tr></tbody>
  </table>
  @endif

  <h3>Purchases</h3>
  <table>
    <thead><tr><th style="width:11%">Date</th><th style="width:14%">Ref</th><th style="width:17%">Warehouse</th><th style="width:12%">Status</th><th class="right" style="width:13%">Grand Total</th><th class="right" style="width:11%">Paid</th><th class="right" style="width:11%">Due</th><th style="width:11%">Payment</th></tr></thead>
    <tbody>
      @forelse($purchases as $purchase)
      <tr>
        <td>{{ supplierLedgerDate($purchase->date, $settings) }}</td><td>{{ $purchase->Ref }}</td><td>{{ optional($purchase->warehouse)->name }}</td>
        <td><span class="{{ supplierLedgerStatus($purchase->statut) }}">{{ $purchase->statut }}</span></td>
        <td class="right">{{ supplierLedgerPrice($purchase->GrandTotal, 2, $priceFormat) }}</td><td class="right">{{ supplierLedgerPrice($purchase->paid_amount, 2, $priceFormat) }}</td>
        <td class="right">{{ supplierLedgerPrice($purchase->GrandTotal - $purchase->paid_amount, 2, $priceFormat) }}</td><td><span class="{{ supplierLedgerPaymentStatus($purchase->payment_statut) }}">{{ $purchase->payment_statut }}</span></td>
      </tr>
      @empty <tr><td colspan="8">No purchases found.</td></tr> @endforelse
    </tbody>
    <tfoot><tr><td colspan="4" class="right">Totals</td><td class="right">{{ supplierLedgerPrice($tablePurchaseGrand, 2, $priceFormat) }}</td><td class="right">{{ supplierLedgerPrice($tablePurchasePaid, 2, $priceFormat) }}</td><td class="right">{{ supplierLedgerPrice($tablePurchaseDue, 2, $priceFormat) }}</td><td></td></tr></tfoot>
  </table>

  <h3>Payments</h3>
  <table>
    <thead><tr><th style="width:14%">Date</th><th style="width:17%">Payment Ref</th><th style="width:17%">Type</th><th style="width:18%">Purchase Ref</th><th style="width:18%">Method</th><th class="right" style="width:16%">Amount</th></tr></thead>
    <tbody>
      @forelse($payments as $payment)
      <tr><td>{{ supplierLedgerDate($payment->date, $settings) }}</td><td>{{ $payment->Ref }}</td><td><span class="pill {{ $payment->payment_type === 'opening_balance' ? 'info' : 'success' }}">{{ $payment->payment_type === 'opening_balance' ? 'Opening Balance' : 'Purchase' }}</span></td><td>{{ $payment->purchase_ref ?? '-' }}</td><td>{{ $payment->payment_method ?? '-' }}</td><td class="right">{{ supplierLedgerPrice($payment->montant, 2, $priceFormat) }}</td></tr>
      @empty <tr><td colspan="6">No payments found.</td></tr> @endforelse
    </tbody>
    <tfoot><tr><td colspan="5" class="right">Total Payments</td><td class="right">{{ supplierLedgerPrice($paymentsTotal, 2, $priceFormat) }}</td></tr></tfoot>
  </table>

  <h3>Purchase Returns</h3>
  <table>
    <thead><tr><th style="width:14%">Ref</th><th style="width:12%">Status</th><th style="width:17%">Purchase Ref</th><th style="width:18%">Warehouse</th><th class="right" style="width:12%">Grand</th><th class="right" style="width:9%">Paid</th><th class="right" style="width:9%">Due</th><th style="width:9%">Payment</th></tr></thead>
    <tbody>
      @forelse($returns as $return)
      <tr><td>{{ $return->Ref }}</td><td><span class="{{ supplierLedgerStatus($return->statut) }}">{{ $return->statut }}</span></td><td>{{ optional($return->purchase)->Ref ?? '-' }}</td><td>{{ optional($return->warehouse)->name }}</td><td class="right">{{ supplierLedgerPrice($return->GrandTotal, 2, $priceFormat) }}</td><td class="right">{{ supplierLedgerPrice($return->paid_amount, 2, $priceFormat) }}</td><td class="right">{{ supplierLedgerPrice($return->GrandTotal - $return->paid_amount, 2, $priceFormat) }}</td><td><span class="{{ supplierLedgerPaymentStatus($return->payment_statut) }}">{{ $return->payment_statut }}</span></td></tr>
      @empty <tr><td colspan="8">No purchase returns found.</td></tr> @endforelse
    </tbody>
    <tfoot><tr><td colspan="5" class="right">Totals</td><td class="right">{{ supplierLedgerPrice($returnGrand, 2, $priceFormat) }}</td><td class="right">{{ supplierLedgerPrice($returnPaid, 2, $priceFormat) }}</td><td class="right">{{ supplierLedgerPrice($returnDue, 2, $priceFormat) }}</td></tr></tfoot>
  </table>

  <h3>Return Refunds</h3>
  <table>
    <thead><tr><th style="width:16%">Date</th><th style="width:20%">Payment Ref</th><th style="width:22%">Return Ref</th><th style="width:24%">Method</th><th class="right" style="width:18%">Amount</th></tr></thead>
    <tbody>
      @forelse($returnRefunds as $refund)
      <tr><td>{{ supplierLedgerDate($refund->date, $settings) }}</td><td>{{ $refund->Ref }}</td><td>{{ $refund->return_ref ?? '-' }}</td><td>{{ $refund->payment_method ?? '-' }}</td><td class="right">{{ supplierLedgerPrice($refund->montant, 2, $priceFormat) }}</td></tr>
      @empty <tr><td colspan="5">No return refunds found.</td></tr> @endforelse
    </tbody>
    <tfoot><tr><td colspan="4" class="right">Total Return Refunds</td><td class="right">{{ supplierLedgerPrice($refundsTotal, 2, $priceFormat) }}</td></tr></tfoot>
  </table>
</body>
</html>
