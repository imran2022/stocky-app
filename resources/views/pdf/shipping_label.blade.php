<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 18px; }
    body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 11pt; }
    .box { border: 2px solid #111827; border-radius: 8px; padding: 16px; }
    .row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
    .ref-badge {
        display: inline-block; background: #6d28d9; color: #fff; font-weight: bold;
        padding: 6px 16px; border-radius: 6px; font-size: 13pt;
    }
    .meta { text-align: right; font-size: 10pt; color: #374151; }
    .meta div { margin-bottom: 2px; }
    .section-title {
        font-size: 8pt; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280;
        margin-bottom: 4px; margin-top: 14px;
    }
    .party-name { font-size: 13pt; font-weight: bold; margin-bottom: 4px; }
    .party-line { font-size: 10.5pt; color: #374151; margin-bottom: 2px; }
    .divider { border-top: 2px dashed #9ca3af; margin: 16px 0; }
    .cod {
        margin-top: 16px; text-align: center; border: 2px solid #ef4444; border-radius: 8px;
        padding: 10px; font-size: 15pt; font-weight: bold; color: #b91c1c;
    }
</style>
</head>
<body>
    <div class="box">
        <div class="row">
            <div class="ref-badge">{{ $sale['Ref'] }}</div>
            <div class="meta">
                <div><strong>{{ __('pdf.date') }}:</strong> {{ $sale['date'] }}</div>
            </div>
        </div>

        <div class="section-title">From</div>
        <div class="party-name">{{ $company['CompanyName'] }}</div>
        @if(!empty($company['CompanyPhone']))<div class="party-line">{{ __('pdf.phone') }}: {{ $company['CompanyPhone'] }}</div>@endif
        @if(!empty($company['CompanyAdress']))<div class="party-line">{{ $company['CompanyAdress'] }}</div>@endif

        <div class="divider"></div>

        <div class="section-title">To</div>
        <div class="party-name">{{ $sale['client_name'] }}</div>
        @if(!empty($sale['client_phone']))<div class="party-line">{{ __('pdf.phone') }}: {{ $sale['client_phone'] }}</div>@endif
        @if(!empty($sale['client_adr']))<div class="party-line">{{ $sale['client_adr'] }}</div>@endif

        @if((float) ($sale['cod_amount'] ?? 0) > 0)
        <div class="cod">
            Cash on Delivery: {{ $symbol }}{{ $sale['cod_amount'] }}
        </div>
        @endif
    </div>
</body>
</html>
