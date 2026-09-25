<?php
// Inventory Costing / Profit Report correction phase — commit 5: Sale Return pack/unit integrity.
//
// A second external review (after update 5's 4 commits) found that SalesReturnController's
// create_sell_return() prefill never sent back a sale line's product_pack_id/pack_multiplier/
// pack_name at all, so the frontend's own `?? 1` fallback silently turned a multi-pack sale into a
// "return of 1 base unit" the moment it was returned — understating both the restocked quantity and
// the Legacy Profit Report's COGS reversal. A second, related bug: a sale line with no explicit
// sale_unit_id had its resolved default unit thrown away by an unconditional `$unit = null;`,
// repeated identically in store()/update()/destroy()/delete_by_selection() — the surfaced failure
// mode was an uncaught FK QueryException (500), not a clean validation error.
//
// Fixed via one shared App\Support\SaleReturnStock: resolveUnit() keeps a resolved fallback instead
// of discarding it; deriveSnapshot() re-derives sale_unit_id/product_pack_id/pack_multiplier/
// pack_name from the ORIGINAL sale detail (by sale_id + product_id + product_variant_id, the same
// key edit_sell_return() already used) — a browser-submitted pack_multiplier/sale_unit_id is never
// trusted for a return line again — and throws (-> a clean 422, caught the same way
// SaleReturnLimits already was) when no matching sale line or no resolvable unit exists.
// baseQuantity()/applyStock() (through StockMutator::lockOrCreate) are the one stock-mutation
// implementation used identically by store(), update(), destroy() and delete_by_selection().
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use App\Http\Controllers\SalesReturnController as SRC;
use App\Http\Controllers\ProfitReportController as PRC;
use App\Services\Costing\InventoryCostingService as Svc;
use App\Services\Costing\CostingReader;
use Illuminate\Support\Facades\DB;

try { DB::table('settings')->update(['allow_overselling' => 1]); } catch (Throwable $e) {}
Svc::setMethod('legacy'); Svc::forgetMethodCache();
$now = now();
$W = 1;

function sru_mkProduct($name, $code, $cost, $price, $unitSaleId = 1) {
    $src = (array) DB::table('products')->where('id', 1)->first(); unset($src['id']);
    $src['name'] = $name; $src['code'] = $code; $src['cost'] = $cost; $src['price'] = $price;
    $src['unit_sale_id'] = $unitSaleId;
    return DB::table('products')->insertGetId($src);
}
$latestReturnId = fn ($saleId) => DB::table('sale_returns')->where('sale_id', $saleId)->orderByDesc('id')->value('id');
$returnDetailFor = fn ($retId, $pid) => (array) DB::table('sale_return_details')->where('sale_return_id', $retId)->where('product_id', $pid)->first();

// =================================================================================================
// 1/5/6/7. Pack sale -> PARTIAL pack return: stock exact, persisted pack snapshot exact, Legacy
// COGS exact — the review's exact reproduction scenario.
// =================================================================================================
$P1 = sru_mkProduct('SRU-PACK', 'SRUP1', 10, 300);
[$c] = mkPurchase($P1, 120, 10, $W, ['date' => '2031-01-01']); check('P1 purchase 120 base @ 10', $c == 200, $c);
$stockAfterPurchase = stock($P1, $W);

// Sell 2 "packs of 6" (12 base units), base sale unit id=1 (1:1)
[$c, $b, $sid1] = mkSale([line($P1, 2, 150, ['sale_unit_id' => 1, 'pack_multiplier' => 6, 'pack_name' => 'Pack of 6'])], ['date' => '2031-01-10', 'warehouse_id' => $W]);
check('P1 sell 2 packs of 6 (12 base units)', $c == 200, $b);
$stockAfterSale = stock($P1, $W);
check('P1 stock after sale = purchase - 12', abs($stockAfterSale - ($stockAfterPurchase - 12)) < 0.001, $stockAfterSale);
$sd1 = (array) DB::table('sale_details')->where('sale_id', $sid1)->first();

// The prefill must now carry the pack snapshot AND a resolved sale_unit_id
[$code, $body] = call(SRC::class, 'create_sell_return', [], 'GET', [$sid1]);
check('P1 create_sell_return prefill answers 200', $code == 200, $body);
$prefill1 = json_decode($body, true)['details'][0];
check('P1 prefill carries pack_multiplier = 6 (the real sale value, not defaulted)', abs(($prefill1['pack_multiplier'] ?? 0) - 6) < 0.001, json_encode($prefill1));
check('P1 prefill carries the product_pack_id snapshot', array_key_exists('product_pack_id', $prefill1));
check('P1 prefill carries the pack_name snapshot', ($prefill1['pack_name'] ?? null) === 'Pack of 6', $prefill1['pack_name'] ?? null);
check('P1 prefill resolves sale_unit_id (not blank)', $prefill1['sale_unit_id'] === 1, json_encode($prefill1['sale_unit_id']));

// Submit a RECEIVED return for a PARTIAL pack quantity (1 of the 2 packs = 6 of the 12 base units),
// deliberately submitting a WRONG pack_multiplier/sale_unit_id to prove the server ignores them and
// re-derives from the original sale line instead.
[$c, $b] = call(SRC::class, 'store', [
    'sale_id' => $sid1, 'client_id' => 2, 'warehouse_id' => $W, 'date' => '2031-01-15', 'statut' => 'received',
    'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 150,
    'details' => [['id' => $sd1['id'], 'product_id' => $P1, 'product_variant_id' => null, 'quantity' => 1, 'Unit_price' => 150,
        'subtotal' => 150, 'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
        'sale_unit_id' => 999999, 'pack_multiplier' => 1, 'pack_name' => 'WRONG', 'imei_number' => null]],
]);
check('P1 received return of 1 pack (partial) created despite a wrong submitted pack_multiplier/sale_unit_id', $c == 200, $b);

$ret1 = $latestReturnId($sid1);
$rd1 = $returnDetailFor($ret1, $P1);
check('P1 persisted return line: pack_multiplier = 6 (derived, NOT the submitted 1)', abs(((float) $rd1['pack_multiplier']) - 6) < 0.001, json_encode($rd1));
check('P1 persisted return line: sale_unit_id = 1 (derived, NOT the submitted 999999)', (int) $rd1['sale_unit_id'] === 1, json_encode($rd1));
check('P1 persisted return line: pack_name = "Pack of 6" (derived, NOT "WRONG")', $rd1['pack_name'] === 'Pack of 6', $rd1['pack_name']);

$stockAfterReturn = stock($P1, $W);
check('P1 stock after return = stockAfterSale + 6 (1 pack = 6 base units, not +1)', abs($stockAfterReturn - ($stockAfterSale + 6)) < 0.001, $stockAfterReturn);

$params = ['from' => '2031-01-01', 'to' => '2031-01-31', 'limit' => -1];
[$pc, $pb] = call(PRC::class, 'index', $params, 'GET', ['product']);
$row1 = collect(json_decode($pb, true)['rows'])->firstWhere('label', 'SRU-PACK');
check('P1 Legacy Profit Report NET cost = (12 - 6) base units x 10 = 60', $row1 && abs($row1['cost'] - 60) < 0.01, json_encode($row1));

// =================================================================================================
// 2/6. Variant + pack return — correct variant identity AND correct pack multiplier together.
// =================================================================================================
$P2 = sru_mkProduct('SRU-VARIANT', 'SRUV1', 999, 999); // parent cost must never be used
$variantId = DB::table('product_variants')->insertGetId(['product_id' => $P2, 'name' => 'Blue', 'cost' => 999, 'price' => 500, 'code' => 'SRUV1-BLUE', 'created_at' => $now, 'updated_at' => $now]);
[$c] = mkPurchase($P2, 60, 20, $W, ['date' => '2031-01-01', 'GrandTotal' => 1200], [], $variantId);
check('P2 variant purchase (60 base units @ 20)', is_array($c) ? false : $c == 200, json_encode($c));
DB::table('purchase_details')->where('product_id', $P2)->update(['product_variant_id' => $variantId]);
$variantStockAfterPurchase = stock($P2, $W, $variantId);

[$c, $b, $sid2] = mkSale([line($P2, 2, 200, ['product_variant_id' => $variantId, 'sale_unit_id' => 1, 'pack_multiplier' => 3, 'pack_name' => 'Pack of 3'])], ['date' => '2031-01-10', 'warehouse_id' => $W]);
check('P2 sell 2 packs of 3, variant (6 base units)', $c == 200, $b);
$variantStockAfterSale = stock($P2, $W, $variantId);
check('P2 variant stock after sale = purchase - 6', abs($variantStockAfterSale - ($variantStockAfterPurchase - 6)) < 0.001, $variantStockAfterSale);
$sd2 = (array) DB::table('sale_details')->where('sale_id', $sid2)->first();

[$c, $b] = call(SRC::class, 'store', [
    'sale_id' => $sid2, 'client_id' => 2, 'warehouse_id' => $W, 'date' => '2031-01-15', 'statut' => 'received',
    'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 200,
    'details' => [['id' => $sd2['id'], 'product_id' => $P2, 'product_variant_id' => $variantId, 'quantity' => 1, 'Unit_price' => 200,
        'subtotal' => 200, 'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
        'sale_unit_id' => 1, 'pack_multiplier' => 1, 'imei_number' => null]],
]);
check('P2 received return of 1 pack (variant) created', $c == 200, $b);
$ret2 = $latestReturnId($sid2);
$rd2 = (array) DB::table('sale_return_details')->where('sale_return_id', $ret2)->where('product_variant_id', $variantId)->first();
check('P2 persisted return line: product_variant_id preserved', (int) $rd2['product_variant_id'] === $variantId, json_encode($rd2));
check('P2 persisted return line: pack_multiplier = 3 (derived)', abs(((float) $rd2['pack_multiplier']) - 3) < 0.001, json_encode($rd2));
$variantStockAfterReturn = stock($P2, $W, $variantId);
check('P2 variant stock after return = +3 base units (1 pack of 3)', abs($variantStockAfterReturn - ($variantStockAfterSale + 3)) < 0.001, $variantStockAfterReturn);

// =================================================================================================
// 3. Null sale_unit_id on the original sale line, but the product HAS a valid default sale unit —
//    the return must resolve and use that default, not treat the line as unresolvable.
// =================================================================================================
$P3 = sru_mkProduct('SRU-NULLUNIT', 'SRUN1', 15, 400, 1); // unit_sale_id = 1 (base, 1:1)
[$c] = mkPurchase($P3, 50, 15, $W, ['date' => '2031-01-01']); check('P3 purchase', $c == 200, $c);
[$c, $b, $sid3] = mkSale([line($P3, 5, 400)], ['date' => '2031-01-10', 'warehouse_id' => $W]);
check('P3 sell 5 units', $c == 200, $b);
$sd3 = (array) DB::table('sale_details')->where('sale_id', $sid3)->first();
DB::table('sale_details')->where('id', $sd3['id'])->update(['sale_unit_id' => null]); // simulate legacy/imported data
$stock3AfterSale = stock($P3, $W);

[$code, $body] = call(SRC::class, 'create_sell_return', [], 'GET', [$sid3]);
check('P3 create_sell_return prefill (null sale_unit_id) answers 200', $code == 200, $body);
$prefill3 = json_decode($body, true)['details'][0];
check('P3 prefill resolves the product default sale unit (not blank)', $prefill3['sale_unit_id'] === 1, json_encode($prefill3['sale_unit_id']));

[$c, $b] = call(SRC::class, 'store', [
    'sale_id' => $sid3, 'client_id' => 2, 'warehouse_id' => $W, 'date' => '2031-01-15', 'statut' => 'received',
    'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 800,
    'details' => [['id' => $sd3['id'], 'product_id' => $P3, 'product_variant_id' => null, 'quantity' => 2, 'Unit_price' => 400,
        'subtotal' => 800, 'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
        'sale_unit_id' => null, 'imei_number' => null]],
]);
check('P3 received return of 2 units (resolved default unit) created', $c == 200, $b);
$stock3AfterReturn = stock($P3, $W);
check('P3 stock after return = +2 (default unit resolved, not silently skipped)', abs($stock3AfterReturn - ($stock3AfterSale + 2)) < 0.001, $stock3AfterReturn);

// =================================================================================================
// 4/9. Completely unresolvable unit -> a clean 422, not an uncaught QueryException/500 — and NO
// header/detail/stock mutation is left behind by the failed attempt.
// =================================================================================================
$P4 = sru_mkProduct('SRU-NOUNIT', 'SRUX1', 15, 400, null); // no default sale unit at all
DB::table('products')->where('id', $P4)->update(['unit_sale_id' => null]);
[$c] = mkPurchase($P4, 50, 15, $W, ['date' => '2031-01-01']); check('P4 purchase', $c == 200, $c);
[$c, $b, $sid4] = mkSale([line($P4, 5, 400)], ['date' => '2031-01-10', 'warehouse_id' => $W]);
check('P4 sell 5 units', $c == 200, $b);
$sd4 = (array) DB::table('sale_details')->where('sale_id', $sid4)->first();
DB::table('sale_details')->where('id', $sd4['id'])->update(['sale_unit_id' => null]);
$stock4Before = stock($P4, $W);
$returnCountBefore = DB::table('sale_returns')->where('sale_id', $sid4)->count();
$detailCountBefore = DB::table('sale_return_details')->count();

[$c, $b] = call(SRC::class, 'store', [
    'sale_id' => $sid4, 'client_id' => 2, 'warehouse_id' => $W, 'date' => '2031-01-15', 'statut' => 'received',
    'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 800,
    'details' => [['id' => $sd4['id'], 'product_id' => $P4, 'product_variant_id' => null, 'quantity' => 2, 'Unit_price' => 400,
        'subtotal' => 800, 'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
        'sale_unit_id' => null, 'imei_number' => null]],
]);
check('P4 unresolvable unit -> a clean 422 (not 500/EXC)', $c == 422, "code=$c body=$b");
check('P4 422 response names the problem (no unit resolvable)', is_string($b) && str_contains($b, 'unit'), $b);
$stock4After = stock($P4, $W);
check('P4 stock UNCHANGED after the failed attempt', abs($stock4After - $stock4Before) < 0.001, "$stock4Before -> $stock4After");
$returnCountAfter = DB::table('sale_returns')->where('sale_id', $sid4)->count();
check('P4 NO sale_return header created by the failed attempt', $returnCountAfter === $returnCountBefore, "$returnCountBefore -> $returnCountAfter");
$detailCountAfter = DB::table('sale_return_details')->count();
check('P4 NO sale_return_details row created by the failed attempt', $detailCountAfter === $detailCountBefore, "$detailCountBefore -> $detailCountAfter");

// =================================================================================================
// 8. Moving Average ledger reversal — the SAME persisted pack_multiplier/sale_unit_id fix that
// corrects Legacy also corrects Moving Average, since MovementSource::saleReturns() reads them
// straight off the same sale_return_details row (no separate MA-specific fix needed).
// =================================================================================================
Svc::setMethod('moving_average'); Svc::forgetMethodCache();
$svc = new Svc;
$svc->syncNewDocuments();
check('MA active for the ledger-reversal check', CostingReader::active());

$P5 = sru_mkProduct('SRU-MAPACK', 'SRUM1', 12, 360);
[$c] = mkPurchase($P5, 120, 12, $W, ['date' => '2031-01-01']); check('P5 purchase 120 base @ 12', $c == 200, $c);
[$c, $b, $sid5] = mkSale([line($P5, 2, 180, ['sale_unit_id' => 1, 'pack_multiplier' => 6, 'pack_name' => 'Pack of 6'])], ['date' => '2031-01-10', 'warehouse_id' => $W]);
check('P5 (MA) sell 2 packs of 6 (12 base units)', $c == 200, $b);
$sd5 = (array) DB::table('sale_details')->where('sale_id', $sid5)->first();
$svc->syncNewDocuments();
$ledgerSaleCost = (float) DB::table('inventory_cost_ledger')->where('product_id', $P5)->where('source_type', 'sale')->sum('value_delta');
check('P5 (MA) ledger sale COGS = 12 base units x 12 = 144', abs(abs($ledgerSaleCost) - 144) < 0.01, $ledgerSaleCost);

[$c, $b] = call(SRC::class, 'store', [
    'sale_id' => $sid5, 'client_id' => 2, 'warehouse_id' => $W, 'date' => '2031-01-15', 'statut' => 'received',
    'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 180,
    'details' => [['id' => $sd5['id'], 'product_id' => $P5, 'product_variant_id' => null, 'quantity' => 1, 'Unit_price' => 180,
        'subtotal' => 180, 'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
        'sale_unit_id' => 1, 'pack_multiplier' => 1, 'imei_number' => null]], // wrong pack_multiplier submitted, must be ignored
]);
check('P5 (MA) received return of 1 pack created', $c == 200, $b);
$svc->syncNewDocuments();
$ledgerReturnCost = (float) DB::table('inventory_cost_ledger')->where('product_id', $P5)->where('source_type', 'sale_return')->sum('value_delta');
check('P5 (MA) ledger return reversal = 6 base units x 12 = 72 (not 1 x 12 = 12)', abs(abs($ledgerReturnCost) - 72) < 0.01, $ledgerReturnCost);
$netLedgerCogs = $ledgerSaleCost + $ledgerReturnCost;
check('P5 (MA) net ledger COGS after partial return = -(12-6) x 12 = -72', abs($netLedgerCogs - (-72)) < 0.01, $netLedgerCogs);

Svc::setMethod('legacy'); Svc::forgetMethodCache();

finish('Sale Return pack/unit integrity (commit 5)');
