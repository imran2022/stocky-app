<?php
// Audit fix (MF-05, external "Must-Fix" audit 2026-09-25): PosController::CreatePOS() trusted the client-submitted
// `product_type` field to decide whether a line was a service (skip stock validation/deduction) or physical. A
// request could describe a PHYSICAL product as `is_service` and bypass stock deduction entirely — the product's
// real type is now always resolved from the database (App\Models\Product::find()->type), the client field is
// ignored. The same spoof existed in the Multi-Pack oversell guard (assertPackStockSufficient(), duplicated in both
// PosController and SalesController) and is fixed identically in both places.
//
// Also fixed in the same pass: CreatePOS's stock-deduction block still used the OLD raw
// `product_warehouse::where(...)->first()` lookup (silently skipped the deduction when no row existed yet for a
// product never stocked in that warehouse, and could match the wrong row for a non-variant product when variant
// rows also existed) instead of the locked, always-creates `StockMutator::lockOrCreate()` every other controller
// already uses.
require __DIR__.'/_audit_lib.php';
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

$W = 1; $today = date('Y-m-d');

function mkProductMF05($name, $code, $cost, $price) {
    $src = (array) DB::table('products')->where('id', 1)->first(); unset($src['id']);
    $src['name'] = $name; $src['code'] = $code; $src['cost'] = $cost; $src['price'] = $price; $src['type'] = 'is_single';
    return DB::table('products')->insertGetId($src);
}
function setPwMF05($pid, $wh, $qty) {
    DB::table('product_warehouse')->where('product_id', $pid)->where('warehouse_id', $wh)->whereNull('product_variant_id')->delete();
    $pw = (array) DB::table('product_warehouse')->where('product_id', 1)->where('warehouse_id', $wh)->whereNull('product_variant_id')->first();
    if (! $pw) { $pw = ['manage_stock' => 1, 'created_at' => now(), 'updated_at' => now()]; } else { unset($pw['id']); }
    $pw['product_id'] = $pid; $pw['warehouse_id'] = $wh; $pw['qte'] = $qty; $pw['product_variant_id'] = null;
    DB::table('product_warehouse')->insert($pw);
}

$pos = function ($lines, $gt, $wh = 1, $extra = []) {
    return call(App\Http\Controllers\PosController::class, 'CreatePOS', array_merge([
        'client_id' => 1, 'warehouse_id' => $wh, 'date' => date('Y-m-d'), 'tax_rate' => 0, 'TaxNet' => 0,
        'discount' => 0, 'discount_Method' => '2', 'shipping' => 0, 'GrandTotal' => $gt, 'notes' => 'audit-mf05',
        'payments' => [['amount' => $gt, 'payment_method_id' => 2, 'account_id' => null, 'change' => 0]], 'details' => $lines,
    ], $extra));
};

// ---------------------------------------------------------------- Type-spoof stock deduction ------------------------
$P1 = mkProductMF05('MF05-SPOOF-PROD', 'MF05SP', 10, 20);
setPwMF05($P1, $W, 50);

// Line claims to be a service (product_type spoofed) even though the product is genuinely physical (is_single).
[$c, $b] = $pos([line($P1, 5, 100, ['product_type' => 'is_service'])], 500);
check('POS accepts the sale (200)', $c == 200, "$c $b");
check(
    'stock WAS deducted despite the spoofed product_type=is_service (bypass closed)',
    stock($P1, $W) == 45,
    'stock is '.stock($P1, $W).' expected 45'
);

// ---------------------------------------------------------------- Type-spoof pack-oversell guard ---------------------
DB::table('settings')->whereNull('deleted_at')->update(['allow_overselling' => 0, 'enable_multi_pack_selling' => 1]);
$P2 = mkProductMF05('MF05-SPOOF-PACK', 'MF05PK', 10, 20);
setPwMF05($P2, $W, 1);   // only 1 unit on hand

// A pack of 4 (needs 4 units) claiming to be a service — must still be rejected, not silently waved through.
[$c, $b] = $pos([line($P2, 1, 400, ['product_type' => 'is_service', 'pack_multiplier' => 4])], 400);
check('pack-oversell guard rejects the spoofed-service pack line (422), stock 1 < needed 4', $c == 422, "$c $b");
check('stock untouched after the rejected pack line', stock($P2, $W) == 1);

DB::table('settings')->whereNull('deleted_at')->update(['allow_overselling' => 0, 'enable_multi_pack_selling' => 0]);

// ---------------------------------------------------------------- Never-stocked product (no product_warehouse row) --
// (overselling allowed here on purpose: the point of this scenario is that a row gets CREATED and correctly
// deducted rather than the deduction being silently skipped — starting from "no row" necessarily goes negative.)
DB::table('settings')->whereNull('deleted_at')->update(['allow_overselling' => 1]);
$P3 = mkProductMF05('MF05-NEVER-STOCKED', 'MF05NS', 10, 20);
DB::table('product_warehouse')->where('product_id', $P3)->delete();   // guarantee no row exists yet for this product/warehouse
check('no product_warehouse row exists yet for the never-stocked product', ! DB::table('product_warehouse')->where('product_id', $P3)->where('warehouse_id', $W)->exists());

[$c, $b] = $pos([line($P3, 3, 100)], 300);
check('POS sale of a never-before-stocked product is accepted (200)', $c == 200, "$c $b");
check(
    'a product_warehouse row now exists and reflects the sale (-3), not silently skipped',
    stock($P3, $W) == -3,
    'stock is '.stock($P3, $W).' expected -3'
);

finish('MF-05: POS canonical product type (no client-trust bypass) + locked stock mutation');
