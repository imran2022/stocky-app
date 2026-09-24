<?php
// Audit Batch 4 (C5): COGS is costed in BASE units. Sale lines convert by unit x pack multiplier, purchase layers by
// purchase unit (cost per base unit = cost / factor), and received sale returns are netted off the quantity consumed.
require __DIR__.'/_audit_lib.php';
use Illuminate\Support\Facades\DB;

DB::table('units')->insert(['id' => 2, 'name' => 'Box', 'ShortName' => 'bx', 'base_unit' => 1, 'operator' => '*', 'operator_value' => 12, 'is_active' => 1]);
$now = now(); $WH = 2; $D0 = '2030-02-10'; $D1 = '2030-02-28';

$purchase = function ($ref, $date, $pid, $qty, $cost, $unit) use ($WH, $now) {
    $id = DB::table('purchases')->insertGetId(['Ref' => $ref, 'date' => $date, 'provider_id' => 1, 'warehouse_id' => $WH, 'user_id' => 2, 'statut' => 'received',
        'GrandTotal' => $qty * $cost, 'TaxNet' => 0, 'shipping' => 0, 'discount' => 0, 'tax_rate' => 0, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('purchase_details')->insert(['purchase_id' => $id, 'product_id' => $pid, 'quantity' => $qty, 'cost' => $cost, 'purchase_unit_id' => $unit,
        'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_method' => '2', 'total' => $qty * $cost, 'created_at' => $now, 'updated_at' => $now]);
};
$sale = function ($ref, $date, $statut, array $lines) use ($WH, $now) {
    $id = DB::table('sales')->insertGetId(['Ref' => $ref, 'date' => $date, 'client_id' => 2, 'warehouse_id' => $WH, 'user_id' => 2, 'statut' => $statut,
        'GrandTotal' => 1, 'TaxNet' => 0, 'shipping' => 0, 'discount' => 0, 'tax_rate' => 0, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'created_at' => $now, 'updated_at' => $now]);
    foreach ($lines as [$pid, $qty, $unit, $pack]) {
        DB::table('sale_details')->insert(['sale_id' => $id, 'date' => $date, 'product_id' => $pid, 'quantity' => $qty, 'price' => 1, 'TaxNet' => 0, 'tax_method' => '1',
            'discount' => 0, 'discount_method' => '2', 'total' => $qty, 'sale_unit_id' => $unit, 'pack_multiplier' => $pack, 'created_at' => $now, 'updated_at' => $now]);
    }
};
$ret = function ($ref, $date, $statut, $pid, $qty) use ($WH, $now) {
    $id = DB::table('sale_returns')->insertGetId(['Ref' => $ref, 'date' => $date, 'client_id' => 2, 'warehouse_id' => $WH, 'user_id' => 2, 'statut' => $statut,
        'GrandTotal' => 1, 'TaxNet' => 0, 'shipping' => 0, 'discount' => 0, 'tax_rate' => 0, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('sale_return_details')->insert(['sale_return_id' => $id, 'product_id' => $pid, 'quantity' => $qty, 'sale_unit_id' => 1, 'price' => 1, 'TaxNet' => 0,
        'tax_method' => '1', 'discount' => 0, 'discount_method' => '2', 'total' => $qty, 'created_at' => $now, 'updated_at' => $now]);
};

// product 2: layer1 = 2 Box @120 (24 base @10), layer2 = 12 base @20
$purchase('P-C5-1', '2030-02-01', 2, 2, 120, 2);
$purchase('P-C5-2', '2030-02-03', 2, 12, 20, 1);
// before the window: 10 base sold, 2 base returned -> 8 base already consumed from layer1
$sale('S-C5-0', '2030-02-05', 'completed', [[2, 10, 1, null]]);
$ret('R-C5-0', '2030-02-06', 'received', 2, 2);
// in the window: 1 Box (12 base) + 3 units x pack 2 (6 base) = 18 sold; 2 base returned -> 16 net
$sale('S-C5-1', '2030-02-10', 'completed', [[2, 1, 2, null], [2, 3, 1, 2]]);
$ret('R-C5-1', '2030-02-12', 'received', 2, 2);
// must be ignored: pending sale, pending return
$sale('S-C5-P', '2030-02-11', 'pending', [[2, 50, 1, null]]);
$ret('R-C5-P', '2030-02-13', 'pending', 2, 7);
// product 3: purchase 10 @ 30, only a return of 2 in the window (no sales) -> negative COGS at average
$purchase('P-C5-3', '2030-02-01', 3, 10, 30, 1);
$ret('R-C5-3', '2030-02-14', 'received', 3, 2);

$harness = new class { use App\Traits\CalculatesCogsAndAverageCost { calcCogsAndAvgCostFast as public run; } };
$r = $harness->run($D0, $D1, $WH, [$WH]);
$near = fn ($a, $b) => abs($a - $b) < 0.0005;

// product 2: FIFO 16 x 10 = 160; AVG (240 + 240)/36 = 13.3333 x 16 = 213.3333.  product 3: -2 x 30 = -60 on both.
check('FIFO COGS = 160 - 60 = 100', $near($r['fifo'], 100), json_encode($r));
check('AVG COGS = 213.3333 - 60 = 153.3333', $near($r['avg'], 153.3333333), json_encode($r));

// a window with no sales and no returns costs nothing
$z = $harness->run('2031-01-01', '2031-01-31', $WH, [$WH]);
check('empty window = 0', $z['fifo'] == 0 && $z['avg'] == 0, json_encode($z));
finish('Audit B4 COGS units and returns');
