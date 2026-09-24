<?php
// Audit Batch 2 (S9/S10): "Allow overselling = off" is enforced server-side on Sales and POS.
require __DIR__.'/_audit_lib.php';
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

$setOver = fn ($v) => DB::table('settings')->whereNull('deleted_at')->update(['allow_overselling' => $v]);
$pos = function ($lines, $gt, $wh = 1) {
    return call(App\Http\Controllers\PosController::class, 'CreatePOS', ['client_id' => 1, 'warehouse_id' => $wh, 'date' => date('Y-m-d'), 'tax_rate' => 0, 'TaxNet' => 0,
        'discount' => 0, 'discount_Method' => '2', 'shipping' => 0, 'GrandTotal' => $gt, 'notes' => 'audit',
        'payments' => [['amount' => $gt, 'payment_method_id' => 2, 'account_id' => null, 'change' => 0]], 'details' => $lines]);
};
$setOver(0);
setStock(1, 2, 3);          // warehouse 2 holds exactly 3 units of product 1
$n0 = Sale::count();

[$c, $b, $sid] = mkSale([line(1, 5, 100)], ['warehouse_id' => 2]);
check('Sales: 5 from stock 3 rejected (422)', $c == 422, "$c $b");
check('rejected sale not saved, stock untouched', Sale::count() == $n0 && stock(1, 2) == 3);
[$c, $b, $sid] = mkSale([line(1, 3, 100)], ['warehouse_id' => 2]);
check('Sales: exactly 3 of 3 accepted', $c == 200, "$c $b");
check('stock now 0', stock(1, 2) == 0);
[$c, $b] = mkSale([line(1, 1, 100)], ['warehouse_id' => 2]);
check('Sales: 1 from empty rejected', $c == 422, "$c $b");
[$c, $b] = mkSale([line(1, 1, 100)], ['warehouse_id' => 2, 'statut' => 'pending']);
check('Sales: pending order does not need stock', $c == 200, "$c $b");
[$c, $b] = mkSale([line(1, 2, 100), line(1, 2, 100)], ['warehouse_id' => 2]);
check('Sales: two lines of the same product are summed (4 from 0) rejected', $c == 422, "$c $b");

// edit: hands old qty back first
setStock(1, 2, 3);
[$c, $b, $se] = mkSale([line(1, 2, 100)], ['warehouse_id' => 2]);       // stock 3 -> 1
$d0 = detIds($se)[0];
[$c, $b] = updSale($se, [line(1, 3, 100, ['id' => $d0])], ['warehouse_id' => 2, 'GrandTotal' => 300]);
check('Sales edit 2 -> 3 (stock 1 + own 2) accepted', $c == 200, "$c $b");
check('stock now 0', stock(1, 2) == 0, stock(1, 2));
[$c, $b] = updSale($se, [line(1, 4, 100, ['id' => detIds($se)[0]])], ['warehouse_id' => 2, 'GrandTotal' => 400]);
check('Sales edit 3 -> 4 (only 3 available) rejected', $c == 422, "$c $b");

// POS
setStock(1, 1, 2);
$n1 = Sale::count();
[$c, $b] = $pos([line(1, 5, 999, ['product_type' => 'is_single'])], 4995);
check('POS: 5 from stock 2 rejected (422)', $c == 422, "$c $b");
check('POS rejected sale not saved, stock untouched', Sale::count() == $n1 && stock(1, 1) == 2);
[$c, $b] = $pos([line(1, 2, 999, ['product_type' => 'is_single'])], 1998);
check('POS: 2 from stock 2 accepted', $c == 200, "$c $b");

// switch on: everything allowed (documented behaviour)
$setOver(1);
[$c, $b] = mkSale([line(1, 5, 100)], ['warehouse_id' => 2]);
check('overselling ON: Sales accepted', $c == 200, "$c $b");
[$c, $b] = $pos([line(1, 5, 999, ['product_type' => 'is_single'])], 4995);
check('overselling ON: POS accepted', $c == 200, "$c $b");
$setOver(0);
finish('Audit B2 overselling');
