<?php
// Moving Average costing: the exact scenario from the costing review, run through the REAL controllers.
//   opening 50 @100, GRN 4 @120, GRN 55 @110, sell 20 @150     -> COGS 2,115.60, GP 884.40, stock 89 / 9,414.40
// Product A: opening stock = the virtual "Opening stock (auto)" adjustment (what ProductsController::store writes).
// Product B: opening stock only in product_warehouse (import / marketplace sync: no document at all).
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use App\Services\Costing\CostingReader;
use App\Services\Costing\InventoryCostingService as Svc;
use Illuminate\Support\Facades\DB;

try { DB::table('settings')->update(['allow_overselling' => 1]); } catch (Throwable $e) {}
$W = 1; $now = now();
function mkProduct($name, $code, $cost, $price) {
    $src = (array) DB::table('products')->where('id', 1)->first(); unset($src['id']);
    $src['name'] = $name; $src['code'] = $code; $src['cost'] = $cost; $src['price'] = $price;
    return DB::table('products')->insertGetId($src);
}
function setPw($pid, $wh, $qty) {
    DB::table('product_warehouse')->where('product_id', $pid)->delete();
    $pw = (array) DB::table('product_warehouse')->where('product_id', 1)->where('warehouse_id', $wh)->first();
    if (! $pw) { $pw = ['manage_stock' => 1, 'created_at' => now(), 'updated_at' => now()]; } else { unset($pw['id']); }
    $pw['product_id'] = $pid; $pw['warehouse_id'] = $wh; $pw['qte'] = $qty; $pw['product_variant_id'] = null;
    DB::table('product_warehouse')->insert($pw);
}
$A = mkProduct('SCEN-A', 'SCA', 100, 150); $B = mkProduct('SCEN-B', 'SCB', 100, 150);
setPw($A, $W, 50); setPw($B, $W, 50);
$adj = DB::table('adjustments')->insertGetId(['date' => '2030-03-01', 'time' => '10:00:00', 'Ref' => 'ADJ-OPEN', 'warehouse_id' => $W, 'items' => 1, 'notes' => 'Opening stock (auto)', 'user_id' => 2, 'created_at' => $now, 'updated_at' => $now]);
DB::table('adjustment_details')->insert(['adjustment_id' => $adj, 'product_id' => $A, 'product_variant_id' => null, 'quantity' => 50, 'type' => 'add', 'created_at' => $now, 'updated_at' => $now]);

foreach ([$A, $B] as $pid) {
    [$c] = call(CT_P, 'store', pHdr($W, ['date' => '2030-03-05', 'GrandTotal' => 480, 'details' => [pl($pid, 4, 120)]])); check("GRN1 product $pid", $c == 200);
    [$c] = call(CT_P, 'store', pHdr($W, ['date' => '2030-03-10', 'GrandTotal' => 6050, 'details' => [pl($pid, 55, 110)]])); check("GRN2 product $pid", $c == 200);
}
[$c, $b, $sid] = mkSale([line($A, 20, 150), line($B, 20, 150)], ['date' => '2030-03-15', 'warehouse_id' => $W]);
check('sale of 20 + 20 created', $c == 200, "$c $b");
check('stock A/B = 89', stk($A, $W) == 89 && stk($B, $W) == 89);

// ---- costing is OFF by default: nothing changes until an admin turns it on ---------------------------------------------
check('default method is legacy', Svc::method() === 'legacy' && ! CostingReader::active());
Svc::setMethod('moving_average'); Svc::forgetMethodCache();
check('moving_average is active after the switch', CostingReader::active());

(new Svc)->syncNewDocuments();
$avg = DB::table('inventory_cost_balances')->where('warehouse_id', $W);
foreach (['A' => $A, 'B' => $B] as $tag => $pid) {
    $bal = DB::table('inventory_cost_balances')->where('product_id', $pid)->where('warehouse_id', $W)->first();
    check("$tag: average cost = 11530/109 = 105.7798", abs($bal->avg_cost - 105.7798) < 0.0001, json_encode($bal));
    check("$tag: on-hand 89, value 9,414.40", abs($bal->qty - 89) < 0.001 && abs($bal->value - 9414.40) < 0.01, json_encode($bal));
    check("$tag: balance is flagged estimated (opening cost came from the master cost)", (int) $bal->is_estimated === 1);
    $line = DB::table('inventory_cost_ledger')->where('product_id', $pid)->where('source_type', 'sale')->first();
    check("$tag: sale line COGS = 2,115.60 (stored per line)", $line && abs(-$line->value_delta - 2115.60) < 0.01, json_encode($line));
}
$seed = DB::table('inventory_cost_seeds')->where('product_id', $B)->first();
check('B: the undocumented opening stock became ONE write-once seed at the master cost 100', $seed && abs($seed->qty_delta - 50) < 0.001 && abs($seed->unit_cost - 100) < 0.0001 && $seed->reason === 'opening_unexplained', json_encode($seed));
check('A: the opening adjustment got a write-once cost stamp (100, master_cost)', DB::table('inventory_cost_stamps')->where('source_id', DB::table('adjustment_details')->where('adjustment_id', $adj)->value('id'))->where('basis', 'master_cost')->where('unit_cost', 100)->exists());

$cogs = CostingReader::cogsForWindow('2030-03-01', '2030-03-31', $W, [$W], true);   // only A/B sold in this window
check('window COGS (A + B) = 4,231.19', abs($cogs - 4231.19) < 0.02, (string) $cogs);
check('gross profit on revenue 6,000 = 1,768.81', abs(6000 - $cogs - 1768.81) < 0.02);
$sv = CostingReader::stockValue($W, [$W], [$A, $B]);
check('stock value (A + B) = 18,828.81 on 178 units', abs($sv['value'] - 18828.81) < 0.02 && abs($sv['qty'] - 178) < 0.001, json_encode($sv));
check('value as of 2030-03-12 (before the sale) = 2 x 11,530', abs(CostingReader::valueAsOf('2030-03-12', $W, [$W], [$A, $B]) - 23060) < 0.05, (string) CostingReader::valueAsOf('2030-03-12', $W, [$W], [$A, $B]));
check('value as of 2030-03-04 = 5,000: only A has a dated opening; B\'s undocumented opening stock is dated at its first movement (documented limitation)', abs(CostingReader::valueAsOf('2030-03-04', $W, [$W], [$A, $B]) - 5000) < 0.05, (string) CostingReader::valueAsOf('2030-03-04', $W, [$W], [$A, $B]));
check('value as of 2030-03-05 (after GRN1) = 2 x 5,480', abs(CostingReader::valueAsOf('2030-03-05', $W, [$W], [$A, $B]) - 10960) < 0.05, (string) CostingReader::valueAsOf('2030-03-05', $W, [$W], [$A, $B]));

// ---- the audit-stability guarantee: master cost edits cannot move history ------------------------------------------------------
DB::table('products')->whereIn('id', [$A, $B])->update(['cost' => 500]);
$cogs2 = CostingReader::cogsForWindow('2030-03-01', '2030-03-31', $W, [$W], true);
$sv2 = CostingReader::stockValue($W, [$W], [$A, $B]);
check('master cost 100 -> 500: COGS unchanged', abs($cogs2 - $cogs) < 0.0001, "$cogs2 vs $cogs");
check('master cost 100 -> 500: stock value unchanged', abs($sv2['value'] - $sv['value']) < 0.0001);
check('master cost 100 -> 500: value as of a past date unchanged', abs(CostingReader::valueAsOf('2030-03-12', $W, [$W], [$A, $B]) - 23060) < 0.05);

// ---- idempotent: re-syncing changes nothing and logs no correction ---------------------------------------------------------------
$before = DB::table('inventory_cost_ledger')->orderBy('id')->get()->map(fn ($r) => [$r->source_type, $r->source_id, $r->value_delta, $r->balance_value])->all();
(new Svc)->syncProducts([$A, $B]);
$after = DB::table('inventory_cost_ledger')->orderBy('id')->get()->map(fn ($r) => [$r->source_type, $r->source_id, $r->value_delta, $r->balance_value])->all();
check('re-sync is idempotent', $before == $after);
check('re-sync logged no corrections', DB::table('inventory_cost_corrections')->count() === 0);
check('nothing is out of sync afterwards', (new Svc)->verify(false)['dirty'] === [], json_encode((new Svc)->verify(false)));
finish('Costing scenario (review scenario, real controllers)');
