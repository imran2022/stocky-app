<?php
// Audit Batch 5: a PENDING sale/purchase return must not move the customer/supplier balance; only received/completed ones count.
require __DIR__.'/_audit_lib.php';
use Illuminate\Support\Facades\DB;

$cid = 2;
$retBefore = (float) DB::table('sale_returns')->where('client_id', $cid)->whereNull('deleted_at')->where('statut', 'received')->sum('GrandTotal');
$base = json_decode(call(App\Http\Controllers\ClientController::class, 'clientBrief', [], 'GET', [$cid])[1], true);
$b0 = (array) ($base['data'] ?? $base);

$cols = array_flip(DB::getSchemaBuilder()->getColumnListing('sale_returns'));
$mk = function (string $statut, float $amt) use ($cid, $cols) {
    $row = ['client_id' => $cid, 'warehouse_id' => 1, 'user_id' => 1, 'date' => date('Y-m-d'), 'Ref' => 'B5P-'.uniqid(), 'statut' => $statut,
            'GrandTotal' => $amt, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'created_at' => now(), 'updated_at' => now()];
    return DB::table('sale_returns')->insertGetId(array_intersect_key($row, $cols));
};
$id = $mk('pending', 500);
$b1 = (array) (json_decode(call(App\Http\Controllers\ClientController::class, 'clientBrief', [], 'GET', [$cid])[1], true)['data'] ?? []);
if (!$b1) { $j = json_decode(call(App\Http\Controllers\ClientController::class, 'clientBrief', [], 'GET', [$cid])[1], true); $b1 = (array) $j; }
check('pending return leaves return_due unchanged', (float) ($b1['return_due'] ?? 0) === (float) ($b0['return_due'] ?? 0), json_encode([$b0['return_due'] ?? null, $b1['return_due'] ?? null]));
check('pending return leaves netBalance unchanged', (float) ($b1['netBalance'] ?? 0) === (float) ($b0['netBalance'] ?? 0));

DB::table('sale_returns')->where('id', $id)->update(['statut' => 'received']);
$j = json_decode(call(App\Http\Controllers\ClientController::class, 'clientBrief', [], 'GET', [$cid])[1], true);
$b2 = (array) ($j['data'] ?? $j);
check('received return raises return_due by its total', abs((float) $b2['return_due'] - ((float) ($b0['return_due'] ?? 0) + 500)) < 0.005, json_encode($b2['return_due'] ?? null));
DB::table('sale_returns')->where('id', $id)->delete();

// supplier side
$pcols = array_flip(DB::getSchemaBuilder()->getColumnListing('purchase_returns'));
$pid = DB::table('purchase_returns')->insertGetId(array_intersect_key(['provider_id' => 1, 'warehouse_id' => 1, 'user_id' => 1, 'date' => date('Y-m-d'), 'Ref' => 'B5PP-'.uniqid(), 'statut' => 'pending',
    'GrandTotal' => 300, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'created_at' => now(), 'updated_at' => now()], $pcols));
$sum = fn () => (float) DB::table('purchase_returns')->where('provider_id', 1)->whereNull('deleted_at')->where('statut', 'completed')->sum('GrandTotal');
$s0 = $sum();
check('pending purchase return not in completed sum', $s0 === (float) DB::table('purchase_returns')->where('provider_id', 1)->whereNull('deleted_at')->where('statut', 'completed')->sum('GrandTotal'));
[$c, $b] = call(App\Http\Controllers\ProvidersController::class, 'show', [], 'GET', [1]);
$d = json_decode($b, true);
$dd = $d['data'] ?? $d;
DB::table('purchase_returns')->where('id', $pid)->update(['statut' => 'completed']);
[$c2, $b2s] = call(App\Http\Controllers\ProvidersController::class, 'show', [], 'GET', [1]);
$d2 = json_decode($b2s, true); $dd2 = $d2['data'] ?? $d2;
$k = isset($dd['return_Due']) ? 'return_Due' : null;
if ($k) check('supplier return_Due: pending ignored, completed counted (+300)', abs(((float) $dd2[$k] - (float) $dd[$k]) - 300) < 0.005, json_encode([$dd[$k], $dd2[$k]]));
else check('supplier show responded', $c === 200 && $c2 === 200, $b);
DB::table('purchase_returns')->where('id', $pid)->delete();
finish('Audit B5 pending returns vs balances');
