<?php
// Audit fix (MF-07, external "Must-Fix" audit 2026-09-25): SalesController had the same "resolve a fallback
// unit, then immediately discard it via an unconditional `$old_unit = null;` / `$unit = null;` placed AFTER the
// resolution attempt" bug already found and fixed in SaleReturnStock/SalesReturnController, independently present
// in 8 more locations in this file. In update() and delete_by_selection() specifically, that discarded unit gated
// the entire stock-restore block (`if ($old_unit) { ... }`), so editing or bulk-deleting an old/legacy sale line
// whose `sale_unit_id` was NULL silently skipped giving its stock back -- the line's stock stayed deducted forever
// even though the sale itself was changed or removed. Fixed by keeping the resolved fallback unit instead of
// discarding it (the other 6 fixed occurrences are display/PDF/prefill methods where the same discard caused a
// missing unit label instead of a stock leak).
require __DIR__.'/_audit_lib.php';
use App\Http\Controllers\SalesController;
use Illuminate\Support\Facades\DB;

$W = 1;

function mkProductMF07($name, $code, $cost, $price) {
    $src = (array) DB::table('products')->where('id', 1)->first(); unset($src['id']);
    $src['name'] = $name; $src['code'] = $code; $src['cost'] = $cost; $src['price'] = $price; $src['type'] = 'is_single';
    return DB::table('products')->insertGetId($src);
}
function setPwMF07($pid, $wh, $qty) {
    DB::table('product_warehouse')->where('product_id', $pid)->where('warehouse_id', $wh)->whereNull('product_variant_id')->delete();
    $pw = (array) DB::table('product_warehouse')->where('product_id', 1)->where('warehouse_id', $wh)->whereNull('product_variant_id')->first();
    if (! $pw) { $pw = ['manage_stock' => 1, 'created_at' => now(), 'updated_at' => now()]; } else { unset($pw['id']); }
    $pw['product_id'] = $pid; $pw['warehouse_id'] = $wh; $pw['qte'] = $qty; $pw['product_variant_id'] = null;
    DB::table('product_warehouse')->insert($pw);
}

DB::table('settings')->whereNull('deleted_at')->update(['allow_overselling' => 0]);

// ---------------------------------------------------------------- update(): legacy null-unit line restores stock ------
$P1 = mkProductMF07('MF07-UPDATE-NULLUNIT', 'MF07UU', 10, 20);
setPwMF07($P1, $W, 100);

[$c, $b, $sid1] = mkSale([line($P1, 5, 20)], ['statut' => 'completed']);
check('sale created (200)', $c == 200, "$c $b");
check('stock deducted by 5 after sale (100 -> 95)', stock($P1, $W) == 95, stock($P1, $W));

$detId1 = detIds($sid1)[0];
// Simulate a legacy row: no sale_unit_id stored at all.
DB::table('sale_details')->where('id', $detId1)->update(['sale_unit_id' => null]);
check('detail row now has sale_unit_id = NULL (simulated legacy row)', DB::table('sale_details')->where('id', $detId1)->value('sale_unit_id') === null);

// Edit the sale: same line, quantity raised 5 -> 8. Expected arithmetic if the old line's stock IS correctly
// restored first: 95 + 5 (handed back) - 8 (reapplied) = 92. If the MF-07 bug were still present, the old line's
// stock would never be handed back and the result would incorrectly be 95 - 8 = 87.
[$c, $b] = updSale($sid1, [array_merge(line($P1, 8, 20), ['id' => $detId1])]);
check('update (qty 5 -> 8) on the null-sale_unit_id legacy line is accepted (200)', $c == 200, "$c $b");
check(
    'stock is 92 (old qty 5 correctly handed back, then new qty 8 reapplied) -- not 87 (leaked restore)',
    stock($P1, $W) == 92,
    'stock is '.stock($P1, $W).' expected 92'
);

// ---------------------------------------------------------------- update(): legacy null-unit line, quantity lowered ---
$P2 = mkProductMF07('MF07-UPDATE-NULLUNIT-LOWER', 'MF07UL', 10, 20);
setPwMF07($P2, $W, 50);

[$c, $b, $sid2] = mkSale([line($P2, 10, 20)], ['statut' => 'completed']);
check('second sale created (200)', $c == 200, "$c $b");
check('stock deducted by 10 (50 -> 40)', stock($P2, $W) == 40, stock($P2, $W));

$detId2 = detIds($sid2)[0];
DB::table('sale_details')->where('id', $detId2)->update(['sale_unit_id' => null]);

// Lower 10 -> 4. Expected: 40 + 10 (handed back) - 4 (reapplied) = 46.
[$c, $b] = updSale($sid2, [array_merge(line($P2, 4, 20), ['id' => $detId2])]);
check('update (qty 10 -> 4) on the null-sale_unit_id legacy line is accepted (200)', $c == 200, "$c $b");
check(
    'stock is 46 (old qty 10 correctly handed back, then new qty 4 reapplied) -- not 36 (leaked restore)',
    stock($P2, $W) == 46,
    'stock is '.stock($P2, $W).' expected 46'
);

// ---------------------------------------------------------------- delete_by_selection(): legacy null-unit line --------
$P3 = mkProductMF07('MF07-BULKDELETE-NULLUNIT', 'MF07BD', 10, 20);
setPwMF07($P3, $W, 30);

[$c, $b, $sid3] = mkSale([line($P3, 6, 20)], ['statut' => 'completed']);
check('third sale created (200)', $c == 200, "$c $b");
check('stock deducted by 6 (30 -> 24)', stock($P3, $W) == 24, stock($P3, $W));

$detId3 = detIds($sid3)[0];
DB::table('sale_details')->where('id', $detId3)->update(['sale_unit_id' => null]);

[$c, $b] = call(SalesController::class, 'delete_by_selection', ['selectedIds' => [$sid3]]);
check('bulk-delete of the sale with a null-sale_unit_id legacy line succeeds (200)', $c == 200, "$c $b");
check(
    'stock is back to 24 + 6 = 30 (the legacy line\'s stock WAS restored, not leaked)',
    stock($P3, $W) == 30,
    'stock is '.stock($P3, $W).' expected 30'
);

finish('MF-07: SalesController legacy null-unit stock reversal (update() + delete_by_selection())');
