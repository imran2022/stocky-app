<?php
// Audit fix (MF-03, external "Must-Fix" audit 2026-09-25): DamageController::store()/update() had no strict
// positive-quantity rule and no authoritative locked availability check — a negative quantity could literally
// INCREASE stock (delta = -(-5) = +5), and a damage quantity above what was actually on hand recorded the FULL
// submitted quantity on the document while `applyStockDelta()`'s $clampFloor silently truncated the real stock
// movement to whatever was available, so the document/batch/movement-history/write-off-expense quantity and the
// real stock movement could disagree. Fixed with a numeric/positive-quantity validation pass (before any write)
// and App\Support\StockGuard::assertAvailable() (the same locked, "Allow overselling"-aware check every other
// stock-changing module already uses) run before store()/update() mutate anything, instead of the silent clamp.
require __DIR__.'/_audit_lib.php';
use App\Http\Controllers\DamageController;
use Illuminate\Support\Facades\DB;

$near = fn ($a, $b, $e = 0.01) => abs($a - $b) <= $e;
$W = 1; $today = date('Y-m-d');

function mkProductMF03($name, $code, $cost, $price) {
    $src = (array) DB::table('products')->where('id', 1)->first(); unset($src['id']);
    $src['name'] = $name; $src['code'] = $code; $src['cost'] = $cost; $src['price'] = $price; $src['type'] = 'is_single';
    return DB::table('products')->insertGetId($src);
}
function setPwMF03($pid, $wh, $qty) {
    DB::table('product_warehouse')->where('product_id', $pid)->where('warehouse_id', $wh)->whereNull('product_variant_id')->delete();
    $pw = (array) DB::table('product_warehouse')->where('product_id', 1)->where('warehouse_id', $wh)->whereNull('product_variant_id')->first();
    if (! $pw) { $pw = ['manage_stock' => 1, 'created_at' => now(), 'updated_at' => now()]; } else { unset($pw['id']); }
    $pw['product_id'] = $pid; $pw['warehouse_id'] = $wh; $pw['qte'] = $qty; $pw['product_variant_id'] = null;
    DB::table('product_warehouse')->insert($pw);
}
$dmg = fn ($qty, $pid, $wh = 1) => call(DamageController::class, 'store', ['warehouse_id' => $wh, 'date' => date('Y-m-d'), 'notes' => 'mf03', 'details' => [['product_id' => $pid, 'product_variant_id' => null, 'quantity' => $qty]]]);

DB::table('settings')->whereNull('deleted_at')->update(['allow_overselling' => 0]);

// ---------------------------------------------------------------- Negative / zero quantity rejected -----------------
$P1 = mkProductMF03('MF03-NEG-QTY', 'MF03NQ', 20, 40);
setPwMF03($P1, $W, 50);
$n0 = DB::table('damages')->count();

[$c, $b] = $dmg(-5, $P1);
check('negative damage quantity rejected (422)', $c == 422, "$c $b");
check('stock UNCHANGED after rejected negative quantity (did not increase)', stock($P1, $W) == 50, stock($P1, $W));
check('no damage document created', DB::table('damages')->count() == $n0);

[$c, $b] = $dmg(0, $P1);
check('zero damage quantity rejected (422)', $c == 422, "$c $b");
check('stock still unchanged after rejected zero quantity', stock($P1, $W) == 50, stock($P1, $W));

[$c, $b] = $dmg('abc', $P1);
check('non-numeric damage quantity rejected (422)', $c == 422, "$c $b");

// ---------------------------------------------------------------- Over-large damage rejected, not clamped -----------
$P2 = mkProductMF03('MF03-OVER-DAMAGE', 'MF03OD', 15, 30);
setPwMF03($P2, $W, 6);
$n1 = DB::table('damages')->count();

[$c, $b] = $dmg(10, $P2);   // only 6 on hand
check('damage of 10 from stock 6 rejected (422), not silently clamped to 6', $c == 422, "$c $b");
check('stock UNTOUCHED (not driven to 0) after rejected over-large damage', stock($P2, $W) == 6, stock($P2, $W));
check('no damage document created for the rejected line', DB::table('damages')->count() == $n1);

// A valid damage within available stock still succeeds and reconciles exactly (document quantity == real movement).
[$c, $b] = $dmg(4, $P2);
check('damage of 4 from stock 6 accepted', $c == 200, "$c $b");
check('stock now 2 (6 - 4), matching the recorded document quantity exactly', stock($P2, $W) == 2, stock($P2, $W));
$recordedQty = (float) DB::table('damage_details')->where('product_id', $P2)->value('quantity');
check('damage_details.quantity (4) matches the real stock movement (4) -- no clamp-induced mismatch', $near($recordedQty, 4));

// ---------------------------------------------------------------- Overselling ON: allowed to go negative, not clamped
DB::table('settings')->whereNull('deleted_at')->update(['allow_overselling' => 1]);
$P3 = mkProductMF03('MF03-OVERSELL-ON', 'MF03OS', 12, 24);
setPwMF03($P3, $W, 3);

[$c, $b] = $dmg(10, $P3);
check('with overselling ON, damage of 10 from stock 3 is accepted', $c == 200, "$c $b");
check(
    'stock goes NEGATIVE (3 - 10 = -7), matching the full recorded quantity -- not clamped to 0',
    $near(stock($P3, $W), -7),
    'stock is '.stock($P3, $W).' expected -7'
);
DB::table('settings')->whereNull('deleted_at')->update(['allow_overselling' => 0]);

// ---------------------------------------------------------------- update(): increase beyond available rejected ------
$P4 = mkProductMF03('MF03-UPDATE-OVER', 'MF03UO', 10, 20);
setPwMF03($P4, $W, 5);
[$c, $b] = $dmg(5, $P4);   // uses all 5
check('initial damage of 5 (all stock) accepted', $c == 200, "$c $b");
$damageId = (int) DB::table('damages')->orderByDesc('id')->value('id');
$detailId = (int) DB::table('damage_details')->where('damage_id', $damageId)->value('id');
check('stock now 0 after using all 5', stock($P4, $W) == 0, stock($P4, $W));

[$c, $b] = call(DamageController::class, 'update', ['warehouse_id' => $W, 'date' => $today, 'notes' => 'mf03-upd', 'details' => [['id' => $detailId, 'product_id' => $P4, 'product_variant_id' => null, 'quantity' => 8]]], 'PUT', [$damageId]);
check('update: raising 5 -> 8 (only 5 total ever available -- old 5 handed back, still short by 3) rejected (422)', $c == 422, "$c $b");
check('stock unchanged by the rejected update (still 0, not partially applied)', stock($P4, $W) == 0, stock($P4, $W));

[$c, $b] = call(DamageController::class, 'update', ['warehouse_id' => $W, 'date' => $today, 'notes' => 'mf03-upd2', 'details' => [['id' => $detailId, 'product_id' => $P4, 'product_variant_id' => null, 'quantity' => 3]]], 'PUT', [$damageId]);
check('update: lowering 5 -> 3 (well within the handed-back 5) accepted', $c == 200, "$c $b");
check('stock now 2 (5 handed back - 3 reapplied)', stock($P4, $W) == 2, stock($P4, $W));

finish('MF-03: Damage quantity validation + authoritative availability check (no silent clamp)');
