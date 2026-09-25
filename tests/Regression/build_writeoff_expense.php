<?php
// Inventory write-off (Damage + Adjustment decreases) is expensed in P&L / Dashboard / Today Summary, in BOTH
// costing modes:
//   - legacy (master cost): App\Support\Reporting\InventoryWriteOffFigures computes it directly from
//     damage_details / adjustment_details at the product's/variant's master cost.
//   - Moving Average (costing on): CostingReader::writeOffCost reads it from the ledger, at the cost the stock
//     actually carried at the moment of the loss (not today's master cost).
// An Adjustment INCREASE is never counted here (business decision: found stock corrects inventory value, it is
// never recognised as income until sold) — checked explicitly below.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use App\Http\Controllers\DamageController;
use App\Http\Controllers\DashboardController as DASH;
use App\Http\Controllers\ReportController as RC;
use App\Http\Controllers\TodaySummaryController as TS;
use App\Services\Costing\CostingReader;
use App\Services\Costing\InventoryCostingService as Svc;
use Illuminate\Support\Facades\DB;

$near = fn ($a, $b, $e = 0.01) => abs($a - $b) <= $e;
$W = 1; $now = now();

function mkProduct2($name, $code, $cost, $price) {
    $src = (array) DB::table('products')->where('id', 1)->first(); unset($src['id']);
    $src['name'] = $name; $src['code'] = $code; $src['cost'] = $cost; $src['price'] = $price;
    return DB::table('products')->insertGetId($src);
}
function setPw2($pid, $wh, $qty) {
    DB::table('product_warehouse')->where('product_id', $pid)->delete();
    $pw = (array) DB::table('product_warehouse')->where('product_id', 1)->where('warehouse_id', $wh)->first();
    if (! $pw) { $pw = ['manage_stock' => 1, 'created_at' => now(), 'updated_at' => now()]; } else { unset($pw['id']); }
    $pw['product_id'] = $pid; $pw['warehouse_id'] = $wh; $pw['qte'] = $qty; $pw['product_variant_id'] = null;
    DB::table('product_warehouse')->insert($pw);
}

$today = date('Y-m-d');
$P = mkProduct2('WO-PROD', 'WOP', 100, 200);   // master cost 100
setPw2($P, $W, 200);

// A purchase at a DIFFERENT cost (120) so Moving Average's blended average diverges from the master cost (100),
// giving the two modes genuinely different, checkable numbers.
[$c, $b] = call(CT_P, 'store', pHdr($W, ['date' => $today, 'GrandTotal' => 12000, 'details' => [pl($P, 100, 120)]]));
check('GRN of 100 @120 (avg becomes (200*100+100*120)/300 = 106.6667)', $c == 200, "$c $b");

// Damage of 10 units.
[$c, $b] = call(DamageController::class, 'store', ['warehouse_id' => $W, 'date' => $today, 'notes' => 'wo', 'details' => [['product_id' => $P, 'product_variant_id' => null, 'quantity' => 10]]]);
check('damage of 10 created', $c == 200, "$c $b");

// Adjustment DECREASE of 5 units (a stock count found less than expected) — also a loss.
[$c, $b] = call(CT_A, 'store', ['date' => $today] + adjPayload($W, 'sub', 5, $P));
check('adjustment -5 created', $c == 200, "$c $b");

// Adjustment INCREASE of 8 units (a stock count found more than expected) — must NEVER show up as write-off/income.
[$c, $b] = call(CT_A, 'store', ['date' => $today] + adjPayload($W, 'add', 8, $P));
check('adjustment +8 created', $c == 200, "$c $b");

$mkReq = fn ($extra = []) => tap(Illuminate\Http\Request::create('/api/x', 'GET', $extra), function ($r) {
    $u = Illuminate\Support\Facades\Auth::guard('api')->user(); $r->setUserResolver(fn () => $u); app()->instance('request', $r);
});

// ---------------------------------------------------------------- LEGACY (costing off) -----------------------------
check('costing is off to start', ! CostingReader::active());
$pl = json_decode(app(RC::class)->ProfitAndLoss($mkReq(['from' => $today, 'to' => $today]))->getContent(), true)['data'];
// Audit fix (MF-14, external "Must-Fix" audit 2026-09-25): the legacy write-off figure no longer values every loss
// at TODAY's live master cost (100) -- it uses a date-anchored, purchases-only historical average as of the
// report's `to` date instead (see App\Support\Reporting\HistoricalCostAtDate / InventoryWriteOffFigures). The only
// purchase on record for this product is the 100-unit GRN @ 120 above (dated today, so it counts as history "as of
// today"); the 200-unit opening stock was seeded directly into product_warehouse, never through a Purchase, so it
// leaves no purchase-history trace and does not enter this average. Historical average = 120, not master cost 100.
$expectedLegacy = 120 * (10 + 5);   // damage 10 + adjustment-decrease 5, at the purchase-history cost 120 — the +8 increase is excluded
check("legacy write-off = damage(10)+adj-out(5) at historical (purchase) cost 120 = $expectedLegacy", $near($pl['inventory_writeoff_sum'], $expectedLegacy), (string) $pl['inventory_writeoff_sum']);
check('legacy profit already has the write-off subtracted', $near($pl['profit_fifo'], $pl['total_revenue'] - $pl['product_cost_fifo'] - $pl['expenses_sum'] - $pl['inventory_writeoff_sum'] + $pl['service_profit']));

$allWh = DB::table('warehouses')->pluck('id')->all();
$dashData = json_decode(app(DASH::class)->report_dashboard($mkReq(['from' => $today, 'to' => $today]), 0, $allWh)->getContent(), true)['report'];
check('Dashboard today_inventory_writeoff = same figure', $near($dashData['today_inventory_writeoff'], $expectedLegacy), json_encode($dashData['today_inventory_writeoff'] ?? null));
check('Dashboard today_profit has it subtracted (matches P&L for the same day)', $near($dashData['today_profit'], $pl['profit_fifo'], 0.5), json_encode([$dashData['today_profit'], $pl['profit_fifo']]));

$ts = json_decode(app(TS::class)->index($mkReq([]))->getContent(), true);
check('Today Summary profit.inventory_writeoff = same figure', $near($ts['profit']['inventory_writeoff'], $expectedLegacy), json_encode($ts['profit']));

// ---------------------------------------------------------------- MOVING AVERAGE (costing on) -----------------------
Svc::setMethod('moving_average'); Svc::forgetMethodCache();
(new Svc)->syncNewDocuments();
check('moving_average is active', CostingReader::active());

// (200 opening seed @100) blended with (100 GRN @120) puts the average strictly between the two costs — never
// exactly the master cost 100, which is the whole point of using the ledger instead of master cost for write-offs.
// (The +8 same-day adjustment also blends in at master cost, so the exact figure depends on tie-break order between
// same-day/same-second documents — checked loosely here on purpose; the ledger-vs-legacy checks below are exact.)
$bal = DB::table('inventory_cost_balances')->where('product_id', $P)->where('warehouse_id', $W)->first();
check('running average is a genuine blend, strictly between 100 and 120', $bal->avg_cost > 100.01 && $bal->avg_cost < 120, json_encode($bal));

$plCosted = json_decode(app(RC::class)->ProfitAndLoss($mkReq(['from' => $today, 'to' => $today]))->getContent(), true)['data'];
// Damage happens BEFORE the adjustment sub/add in the same-second sequence above; MovementSource orders by
// (occurred_at, type priority: adjustment 50 < damage 80) then falls back to id, so the exact running average
// the damage/sub see may shift slightly — read it straight from the ledger instead of re-deriving it here.
$ledgerWriteoff = (float) DB::table('inventory_cost_ledger')
    ->where(fn ($q) => $q->where('source_type', 'damage')->orWhere(fn ($w) => $w->where('source_type', 'adjustment')->where('qty_delta', '<', 0)))
    ->where('product_id', $P)
    ->selectRaw('COALESCE(SUM(-value_delta), 0) as v')->value('v');
check('costed write-off uses the LEDGER cost, not the master cost (so it differs from the legacy figure)', ! $near($plCosted['inventory_writeoff_sum'], $expectedLegacy, 0.01), (string) $plCosted['inventory_writeoff_sum']);
check('costed write-off == the ledger rows for this product (damage + adjustment decrease only)', $near($plCosted['inventory_writeoff_sum'], $ledgerWriteoff), json_encode([$plCosted['inventory_writeoff_sum'], $ledgerWriteoff]));
check('costed profit already has the costed write-off subtracted', $near($plCosted['profit_fifo'], $plCosted['total_revenue'] - $plCosted['product_cost_fifo'] - $plCosted['expenses_sum'] - $plCosted['inventory_writeoff_sum'] + $plCosted['service_profit']));

// The +8 increase never appears anywhere: neither as an expense (already checked above) nor as income.
$adjInLedger = DB::table('inventory_cost_ledger')->where('product_id', $P)->where('source_type', 'adjustment')->where('qty_delta', '>', 0)->exists();
check('the +8 increase IS in the ledger (it still corrects inventory value)...', $adjInLedger);
check('...but never counted in writeOffCost (only qty_delta < 0 rows are)', $near(CostingReader::writeOffCost($today, $today, $W, [$W]), $ledgerWriteoff));

finish('Inventory write-off (Damage + Adjustment decrease) expensed in P&L/Dashboard/Today Summary');
