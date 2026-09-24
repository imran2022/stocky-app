<?php
// Audit Batch 1 (S2/S3): server recomputes sale totals; tampered totals are rejected on Sales AND POS,
// legitimate ones (tax, discount, inclusive tax, shipping, promo-free POS) still pass.
// Run: php tests/Regression/audit_b1_sale_totals.php   (needs a MySQL/SQLite DB with the demo dataset)
require __DIR__.'/_audit_lib.php';
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

$ok = function ($lines, $o = []) { [$c, $b] = mkSale($lines, $o); return $c == 200; };
$rej = function ($lines, $o = []) { [$c, $b] = mkSale($lines, $o); return $c == 422; };

echo "== Sales::store =====\n";
check('exact sale, no tax accepted', $ok([line(1, 1, 1000)]));
check('15% order tax correct accepted', $ok([line(1, 1, 1000)], ['tax_rate' => 15, 'TaxNet' => 150, 'GrandTotal' => 1150]));
check('tax_rate 15 but TaxNet 0 rejected', $rej([line(1, 1, 1000)], ['tax_rate' => 15, 'TaxNet' => 0, 'GrandTotal' => 1000]));
check('tax_rate 0 but TaxNet 500 rejected', $rej([line(1, 1, 1000)], ['tax_rate' => 0, 'TaxNet' => 500, 'GrandTotal' => 1500]));
check('TaxNet 5000 rejected', $rej([line(1, 1, 1000)], ['tax_rate' => 0, 'TaxNet' => 5000, 'GrandTotal' => 6000]));
check('GT understated by 2% rejected', $rej([line(1, 1, 1000)], ['GrandTotal' => 980]));
check('GT off by 0.30 (rounding) accepted', $ok([line(1, 1, 1000)], ['GrandTotal' => 999.70]));
check('line 20% excl tax with subtotal 100 (should be 120) rejected', $rej([line(1, 1, 100, ['tax_percent' => 20, 'subtotal' => 100])], ['GrandTotal' => 100]));
check('line 20% excl tax correct (120) accepted', $ok([line(1, 1, 100, ['tax_percent' => 20, 'subtotal' => 120])], ['GrandTotal' => 120]));
check('line inclusive tax correct (100) accepted', $ok([line(1, 1, 100, ['tax_percent' => 20, 'tax_method' => '2', 'subtotal' => 100])], ['GrandTotal' => 100]));
check('10% line discount correct accepted', $ok([line(1, 2, 100, ['discount' => 10, 'discount_Method' => '1', 'subtotal' => 180])], ['GrandTotal' => 180]));
check('order discount fixed 100 + shipping 50 accepted', $ok([line(1, 1, 1000)], ['discount' => 100, 'shipping' => 50, 'GrandTotal' => 950]));
check('order discount 10% + tax 5% accepted', $ok([line(1, 1, 1000)], ['discount' => 10, 'discount_Method' => '1', 'tax_rate' => 5, 'TaxNet' => 45, 'GrandTotal' => 945]));
check('order discount not applied in GT rejected', $rej([line(1, 1, 1000)], ['discount' => 100, 'GrandTotal' => 1000]));

echo "== Sales::update ====\n";
[$c, $b, $sid] = mkSale([line(1, 1, 1000)]);
$d0 = detIds($sid)[0];
[$c, $b] = updSale($sid, [line(1, 1, 1000, ['id' => $d0])], ['tax_rate' => 15, 'TaxNet' => 0, 'GrandTotal' => 1000]);
check('update with tax bypass rejected', $c == 422, "$c $b");
[$c, $b] = updSale($sid, [line(1, 1, 1000, ['id' => $d0])], ['tax_rate' => 10, 'TaxNet' => 100, 'GrandTotal' => 1100]);
check('update with correct tax accepted', $c == 200, "$c $b");

echo "== POS CreatePOS ====\n";
$pos = function ($lines, $gt, $extra = []) {
    $p = array_merge(['client_id' => 1, 'warehouse_id' => 1, 'date' => date('Y-m-d'), 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0,
        'discount_Method' => '2', 'shipping' => 0, 'GrandTotal' => $gt, 'notes' => 'audit',
        'payments' => [['amount' => $gt, 'payment_method_id' => 2, 'account_id' => null, 'change' => 0]], 'details' => $lines], $extra);
    return call(App\Http\Controllers\PosController::class, 'CreatePOS', $p);
};
$n0 = Sale::count(); $s0 = stock(1);
[$c, $b] = $pos([line(1, 1, 999, ['product_type' => 'is_single'])], 1);
check('POS: 999 item with GrandTotal 1 rejected', $c == 422, "$c $b");
check('POS: rejected sale not saved, stock untouched', Sale::count() == $n0 && stock(1) == $s0);
[$c, $b] = $pos([line(1, 1, 999, ['product_type' => 'is_single'])], 999);
check('POS: honest sale accepted', $c == 200, "$c $b");
[$c, $b] = $pos([line(1, 1, 1000, ['product_type' => 'is_single'])], 1100, ['tax_rate' => 10, 'TaxNet' => 100]);
check('POS: honest sale with 10% tax accepted', $c == 200, "$c $b");
[$c, $b] = $pos([line(1, 1, 1000, ['product_type' => 'is_single'])], 1000, ['tax_rate' => 10, 'TaxNet' => 0]);
check('POS: tax bypass rejected', $c == 422, "$c $b");
[$c, $b] = $pos([line(1, 1, 1000, ['product_type' => 'is_single', 'subtotal' => 10])], 10);
check('POS: tampered line subtotal rejected', $c == 422, "$c $b");
finish('Audit B1 sale totals');
