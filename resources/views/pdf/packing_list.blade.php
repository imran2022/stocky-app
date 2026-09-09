<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 24px; }
    body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 10.5pt; }
    h1 { font-size: 15pt; margin: 0 0 4px; }
    .meta { font-size: 10pt; color: #374151; margin-bottom: 16px; }
    .meta span { margin-right: 18px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    thead th {
        background: #111827; color: #fff; text-align: left; padding: 6px 8px; font-size: 9pt;
        text-transform: uppercase;
    }
    thead th.num { text-align: right; }
    tbody td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; font-size: 10pt; }
    tbody td.num { text-align: right; }
    tbody tr:nth-child(even) { background: #f9fafb; }
    .code { color: #6b7280; font-size: 8.5pt; }
    tfoot td { padding: 8px; font-weight: bold; border-top: 2px solid #111827; }
</style>
</head>
<body>
    <h1>Packing List</h1>
    <div class="meta">
        <span><strong>Ref:</strong> {{ $sale['Ref'] }}</span>
        <span><strong>Date:</strong> {{ $sale['date'] }}</span>
        <span><strong>Customer:</strong> {{ $sale['client_name'] }}</span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>Code / SKU</th>
                <th class="num">Quantity</th>
                <th class="num">Box</th>
            </tr>
        </thead>
        <tbody>
            @foreach($details as $detail)
            <tr>
                <td>{{ $detail['name'] }}</td>
                <td class="code">{{ $detail['code'] }}</td>
                <td class="num">{{ $detail['quantity'] }} {{ $detail['unitSale'] ?? '' }}</td>
                <td class="num">{{ $detail['box_qty'] !== null ? $detail['box_qty'] : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total items</td>
                <td class="num">{{ $totalQty }}</td>
                <td class="num">{{ $totalBoxes !== null ? $totalBoxes : '—' }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
