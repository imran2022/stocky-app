<?php
// Audit fix: AdjustmentController::adjustment_pdf() used to price every line at the flat legacy master/variant
// cost, even while Moving Average was active — while ReportController::stockAdjustmentReport() (a report screen
// for the SAME Adjustment documents) was already correctly dual-mode aware via CostingReader. Under Moving
// Average, the printed Adjustment PDF and the report screen could show two different cost numbers for the
// identical document. Fixed with one new App\Support\Reporting\AdjustmentLineCost, used by BOTH the report (already
// used its own inline formula, unchanged) and the PDF controller (new).
//
// This test does not parse the rendered PDF (no reliable numeric readback from HTML/PDF bytes); instead it proves
// PARITY at the source: AdjustmentLineCost::forDetails() — the exact class adjustment_pdf() now calls — returns,
// for the same detail ids, the same per-line ledger cost that stockAdjustmentReport()'s own inline SQL formula
// produces for the same adjustment. It also proves the bug WOULD have fired in this scenario (moving-average cost
// genuinely differs from the flat master cost), and smoke-tests that adjustment_pdf() still renders successfully
// in both costing modes.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use App\Http\Controllers\AdjustmentController;
use App\Http\Controllers\ReportController as RC;
use App\Services\Costing\CostingReader;
use App\Services\Costing\InventoryCostingService as Svc;
use App\Support\Reporting\AdjustmentLineCost;
use Illuminate\Support\Facades\DB;

$near = fn ($a, $b, $e = 0.01) => abs($a - $b) <= $e;
$W = 1; $today = date('Y-m-d');

function mkProductAdj($name, $code, $cost, $price) {
    $src = (array) DB::table('products')->where('id', 1)->first(); unset($src['id']);
    $src['name'] = $name; $src['code'] = $code; $src['cost'] = $cost; $src['price'] = $price;
    return DB::table('products')->insertGetId($src);
}
function setPwAdj($pid, $wh, $qty) {
    DB::table('product_warehouse')->where('product_id', $pid)->delete();
    $pw = (array) DB::table('product_warehouse')->where('product_id', 1)->where('warehouse_id', $wh)->first();
    if (! $pw) { $pw = ['manage_stock' => 1, 'created_at' => now(), 'updated_at' => now()]; } else { unset($pw['id']); }
    $pw['product_id'] = $pid; $pw['warehouse_id'] = $wh; $pw['qte'] = $qty; $pw['product_variant_id'] = null;
    DB::table('product_warehouse')->insert($pw);
}

$P = mkProductAdj('ADJ-PDF-PROD', 'ADJPDF', 50, 100);   // master cost 50
setPwAdj($P, $W, 300);

// GRN at a DIFFERENT cost (90) so the Moving Average blend genuinely diverges from the master cost.
[$c, $b] = call(CT_P, 'store', pHdr($W, ['date' => $today, 'GrandTotal' => 9000, 'details' => [pl($P, 100, 90)]]));
check('GRN of 100 @90 created', $c == 200, "$c $b");

// One Adjustment with an ADD and a SUB line (two detail rows, one document).
[$code, $body] = call(AdjustmentController::class, 'store', ['date' => $today] + [
    'warehouse_id' => $W, 'notes' => 'pdf-parity',
    'details' => [
        ['id' => 0, 'product_id' => $P, 'product_variant_id' => null, 'quantity' => 12, 'type' => 'add', 'no_unit' => 1],
        ['id' => 0, 'product_id' => $P, 'product_variant_id' => null, 'quantity' => 4, 'type' => 'sub', 'no_unit' => 1],
    ],
]);
check('adjustment (add 12 / sub 4) created', $code == 200, "$code $body");
$adjId = (int) DB::table('adjustments')->max('id');
$detailIds = DB::table('adjustment_details')->where('adjustment_id', $adjId)->pluck('id')->all();
check('two detail rows recorded', count($detailIds) === 2, json_encode($detailIds));

$mkReq = fn ($extra = []) => tap(Illuminate\Http\Request::create('/api/x', 'GET', $extra), function ($r) {
    $u = Illuminate\Support\Facades\Auth::guard('api')->user(); $r->setUserResolver(fn () => $u); app()->instance('request', $r);
});

// ---------------------------------------------------------------- LEGACY (costing off) -----------------------------
check('costing is off to start', ! CostingReader::active());
check('AdjustmentLineCost returns nothing while costing is off (PDF keeps its own legacy formula)', AdjustmentLineCost::forDetails($detailIds) === []);

[$pdfCode] = call(AdjustmentController::class, 'adjustment_pdf', [], 'GET', [$adjId]);
check('adjustment_pdf renders (legacy mode)', $pdfCode == 200, (string) $pdfCode);

// ---------------------------------------------------------------- MOVING AVERAGE (costing on) -----------------------
Svc::setMethod('moving_average'); Svc::forgetMethodCache();
(new Svc)->syncNewDocuments();
check('moving_average is active', CostingReader::active());

$bal = DB::table('inventory_cost_balances')->where('product_id', $P)->where('warehouse_id', $W)->first();
check('running average is a genuine blend, strictly between 50 and 90 (so the bug would have fired)', $bal->avg_cost > 50.01 && $bal->avg_cost < 90, json_encode($bal));

// The report screen's own inline formula (unchanged) for this one adjustment:
$reportRow = json_decode(app(RC::class)->stockAdjustmentReport($mkReq(['from' => $today, 'to' => $today, 'limit' => -1]))->getContent(), true)['data']['rows'];
$thisRow = collect($reportRow)->firstWhere('adj_id', $adjId);
check('adjustment appears in stockAdjustmentReport', $thisRow !== null, json_encode($reportRow));

// AdjustmentLineCost — the exact class adjustment_pdf() now calls — for the same detail ids:
$costMap = AdjustmentLineCost::forDetails($detailIds);
check('AdjustmentLineCost returns a cost for both detail rows', count($costMap) === 2, json_encode($costMap));
$sumFromHelper = array_sum($costMap);
check(
    'AdjustmentLineCost total == stockAdjustmentReport purchase_cost for the identical document (PDF and report can no longer disagree)',
    $near($sumFromHelper, $thisRow['purchase_cost']),
    json_encode(['helper' => $sumFromHelper, 'report' => $thisRow['purchase_cost']])
);

// Prove the OLD bug would really have fired here: flat master/variant cost * qty differs from the ledger cost.
$flatLegacy = 50 * (12 + 4);
check('moving-average cost genuinely differs from the flat legacy figure (confirms the divergence this fix closes)', ! $near($sumFromHelper, $flatLegacy, 0.01), json_encode(['helper' => $sumFromHelper, 'flatLegacy' => $flatLegacy]));

[$pdfCode2] = call(AdjustmentController::class, 'adjustment_pdf', [], 'GET', [$adjId]);
check('adjustment_pdf renders (moving-average mode)', $pdfCode2 == 200, (string) $pdfCode2);

finish('Adjustment PDF / stockAdjustmentReport costing parity (Moving Average)');
