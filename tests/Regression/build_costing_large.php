<?php
// Moving Average costing on a LARGE, realistic data set (default ~200k document lines, 2 years, 3 warehouses, 200 products):
// purchases in pieces and boxes with line discounts / inclusive & exclusive tax, sales (1-3 lines per invoice), sale returns costed at the
// original sale, purchase returns, adjustments, damages, approved transfers, imported opening stock with no document.
// The GENERATOR keeps its own moving-average books while it writes the documents (independent of the app's engine), so every stored sale-line
// cost, every closing balance, every monthly COGS and every report can be checked exactly. Also prints timings.
// usage: php build_costing_large.php [products=200] [days=730] [seed=7]
require __DIR__.'/_audit_lib.php';
use App\Http\Controllers\DashboardController as DASH;
use App\Http\Controllers\ProfitReportController as PRC;
use App\Http\Controllers\ReportController as RC;
use App\Services\Costing\CostingReader;
use App\Services\Costing\InventoryCostingService as Svc;
use Illuminate\Support\Facades\DB;

$NP = (int) ($argv[1] ?? 200); $DAYS = (int) ($argv[2] ?? 730); $seed = (int) ($argv[3] ?? 7);
mt_srand($seed);
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('units')->insertOrIgnore(['id' => 2, 'name' => 'Box', 'ShortName' => 'bx', 'base_unit' => 1, 'operator' => '*', 'operator_value' => 12, 'is_active' => 1]);
$t0 = microtime(true); $lap = function (string $what) use (&$t0) { $now = microtime(true); printf("  [time] %-58s %6.2fs\n", $what, $now - $t0); $t0 = $now; };
$near = fn ($a, $b, $e = 0.01) => abs($a - $b) <= $e;
$WH = [1, 2, 3]; $start = strtotime('2029-01-01');

// ---------------------------------------------------------------- generate: documents + independent books -----------------------------------------
$src = (array) DB::table('products')->where('id', 1)->first(); unset($src['id']);
$pids = []; $master = [];
for ($i = 1; $i <= $NP; $i++) {
    $m = mt_rand(600, 1300) / 100; $r = $src; $r['name'] = "LG-$i"; $r['code'] = 'LG'.str_pad($i, 5, '0', STR_PAD_LEFT); $r['cost'] = $m; $r['price'] = round($m * 1.4, 2);
    $r['unit_id'] = 1; $r['unit_sale_id'] = 1; $r['unit_purchase_id'] = 1;
    $pids[$i] = DB::table('products')->insertGetId($r); $master[$pids[$i]] = $m;
}
$nextPur = (int) DB::table('purchases')->max('id') + 1; $nextPurD = (int) DB::table('purchase_details')->max('id') + 1;
$nextSale = (int) DB::table('sales')->max('id') + 1; $nextSaleD = (int) DB::table('sale_details')->max('id') + 1;
$nextSR = (int) DB::table('sale_returns')->max('id') + 1; $nextSRD = (int) DB::table('sale_return_details')->max('id') + 1;
$nextPR = (int) DB::table('purchase_returns')->max('id') + 1; $nextPRD = (int) DB::table('purchase_return_details')->max('id') + 1;
$nextAdj = (int) DB::table('adjustments')->max('id') + 1; $nextAdjD = (int) DB::table('adjustment_details')->max('id') + 1;
$nextDmg = (int) DB::table('damages')->max('id') + 1; $nextDmgD = (int) DB::table('damage_details')->max('id') + 1;
$nextTr = (int) DB::table('transfers')->max('id') + 1; $nextTrD = (int) DB::table('transfer_details')->max('id') + 1;

$P = []; $PD = []; $SLINES = []; $SR = []; $SRD = []; $PR = []; $PRD = []; $AJ = []; $AJD = []; $DM = []; $DMD = []; $TR = []; $TRD = [];
$expected = ['sale' => [], 'ret' => [], 'bal' => [], 'month' => [], 'snap' => 0.0, 'writeoff' => 0.0];   // sale detail id => cogs ; per key final ; month => cogs ; value on 2029-12-31 ; writeoff = damage + adjustment-decrease cost (adjustment-increase excluded, not income)
$snapDate = '2029-12-31'; $snapValue = 0.0;
$nowTs = now();

foreach ($pids as $idx => $pid) {
    $st = []; foreach ($WH as $w) { $st[$w] = ['q' => 0.0, 'avg' => 0.0]; }
    $opening = [];   // imported opening stock (no document) for ~15% of products in warehouse 1
    if (mt_rand(1, 100) <= 15) { $oq = mt_rand(20, 80); $st[1] = ['q' => (float) $oq, 'avg' => $master[$pid]]; $opening[1] = $oq; }
    $events = [];     // date => list of [prio, seq, payload]
    $seqn = 0; $cost = mt_rand(700, 1100) / 100;
    for ($d = 0; $d < $DAYS; $d++) {
        $date = date('Y-m-d', $start + $d * 86400);
        foreach ($WH as $w) {
            if ($d % 14 === ($w + $idx) % 14) {                                   // a delivery every two weeks per warehouse
                $cost = max(4.0, round($cost + mt_rand(-60, 70) / 100, 2));
                $events[$date][] = [10, ++$seqn, ['t' => 'purchase', 'w' => $w, 'q' => mt_rand(60, 240), 'c' => $cost, 'box' => mt_rand(1, 10) === 1, 'var' => mt_rand(1, 4)]];
            }
            if (mt_rand(1, 100) <= 55) { $events[$date][] = [20, ++$seqn, ['t' => 'sale', 'w' => $w, 'q' => mt_rand(1, 9)]]; }
            if (mt_rand(1, 100) <= 1 && empty($trDay[$idx.'|'.$date])) { $trDay[$idx.'|'.$date] = 1; $events[$date][] = [30, ++$seqn, ['t' => 'transfer', 'w' => $w, 'to' => $WH[($w) % 3], 'q' => mt_rand(3, 15)]]; }
            if (mt_rand(1, 1000) <= 4) { $events[$date][] = [50, ++$seqn, ['t' => 'adjust', 'w' => $w, 'q' => mt_rand(1, 6), 'add' => mt_rand(0, 2) === 0]]; }
            if (mt_rand(1, 100) <= 2) { $events[$date][] = [60, ++$seqn, ['t' => 'sret', 'w' => $w]]; }
            if (mt_rand(1, 1000) <= 3) { $events[$date][] = [70, ++$seqn, ['t' => 'pret', 'w' => $w]]; }
            if (mt_rand(1, 1000) <= 3) { $events[$date][] = [80, ++$seqn, ['t' => 'damage', 'w' => $w, 'q' => mt_rand(1, 3)]]; }
        }
    }
    $lastPurchase = []; $soldLines = [];   // for returns: per warehouse
    ksort($events);
    foreach ($events as $date => $list) {
        if ($date > $snapDate && $snapValue !== null && ! isset($snapDone[$pid])) { foreach ($WH as $w) { $snapValue += max(0, $st[$w]['q']) * $st[$w]['avg']; } $snapDone[$pid] = true; }
        usort($list, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        foreach ($list as [$pr, $sq, $e]) {
            $w = $e['w'];
            switch ($e['t']) {
                case 'purchase':
                    $box = $e['box']; $bq = $box ? max(1, intdiv($e['q'], 12)) : $e['q']; $baseQ = $box ? $bq * 12 : $bq;
                    $var = $e['var']; $c = $e['c'];
                    // build the stored line so that the app's Net Unit Cost equals the base cost c exactly (or, for inclusive tax, as close as decimal(15,3) allows)
                    $disc = 0.0; $dm = '2'; $tax = 0.0; $tm = '1'; $lineCost = $c * ($box ? 12 : 1);
                    if ($var === 2) { $disc = 0.5 * ($box ? 12 : 1); $lineCost = $lineCost + $disc; }                // fixed discount per purchase unit
                    if ($var === 3) { $tax = 5.0; $tm = '1'; }                                                        // exclusive tax: excluded from cost
                    if ($var === 4) { $tax = 10.0; $tm = '2'; $lineCost = round($lineCost / 0.9, 3); }               // inclusive tax per the app's own Net_cost rule
                    $lineCost = round($lineCost, 3);
                    $dAmt = $dm === '2' ? $disc : $lineCost * $disc / 100;
                    $netUnit = $lineCost - $dAmt - ($tm === '2' ? $tax * ($lineCost - $dAmt) / 100 : 0);
                    $base = $netUnit / ($box ? 12 : 1);
                    $hid = $nextPur++;
                    $P[] = ['id' => $hid, 'Ref' => 'LG-P-'.$hid, 'date' => $date, 'provider_id' => 1, 'warehouse_id' => $w, 'user_id' => 2, 'statut' => 'received', 'GrandTotal' => $bq * $lineCost, 'TaxNet' => 0, 'shipping' => 0, 'discount' => 0, 'tax_rate' => 0, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"];
                    $did = $nextPurD++;
                    $PD[] = ['id' => $did, 'purchase_id' => $hid, 'product_id' => $pid, 'quantity' => $bq, 'cost' => $lineCost, 'purchase_unit_id' => $box ? 2 : 1, 'TaxNet' => $tax, 'tax_method' => $tm, 'discount' => $disc, 'discount_method' => $dm, 'total' => $bq * $lineCost, 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"];
                    $s = &$st[$w];
                    if ($s['q'] <= 0) { $nq = $s['q'] + $baseQ; if ($nq > 0) { $s['avg'] = $base; } elseif ($s['avg'] <= 0) { $s['avg'] = $base; } $s['q'] = $nq; }
                    else { $s['avg'] = ($s['q'] * $s['avg'] + $baseQ * $base) / ($s['q'] + $baseQ); $s['q'] += $baseQ; }
                    unset($s);
                    $lastPurchase[$w][] = ['hid' => $hid, 'q' => $baseQ, 'unit' => $base, 'box' => $box, 'lineCost' => $lineCost, 'bq' => $bq, 'tax' => $tax, 'tm' => $tm, 'disc' => $disc, 'dm' => $dm];
                    break;
                case 'sale':
                    if ($st[$w]['q'] < $e['q']) { break; }
                    $u = $st[$w]['avg']; $st[$w]['q'] -= $e['q'];
                    $lid = $nextSaleD++; $price = round($master[$pid] * mt_rand(125, 175) / 100, 2);
                    $SLINES[] = ['id' => $lid, 'pid' => $pid, 'w' => $w, 'date' => $date, 'q' => $e['q'], 'price' => $price];
                    $expected['sale'][$lid] = $u * $e['q']; $expected['month'][substr($date, 0, 7)] = ($expected['month'][substr($date, 0, 7)] ?? 0) + $u * $e['q'];
                    $soldLines[$w][] = [$lid, $e['q'], $u, $date, 0];      // [detail id, qty, unit cost, date, already returned]
                    break;
                case 'transfer':
                    if ($st[$w]['q'] < $e['q']) { break; }
                    $u = $st[$w]['avg']; $st[$w]['q'] -= $e['q']; $to = $e['to'];
                    $sd = &$st[$to];
                    if ($sd['q'] <= 0) { $nq = $sd['q'] + $e['q']; if ($nq > 0) { $sd['avg'] = $u; } elseif ($sd['avg'] <= 0) { $sd['avg'] = $u; } $sd['q'] = $nq; }
                    else { $sd['avg'] = ($sd['q'] * $sd['avg'] + $e['q'] * $u) / ($sd['q'] + $e['q']); $sd['q'] += $e['q']; }
                    unset($sd);
                    $tid = $nextTr++;
                    $TR[] = ['id' => $tid, 'user_id' => 2, 'Ref' => 'LG-T-'.$tid, 'date' => $date, 'from_warehouse_id' => $w, 'to_warehouse_id' => $to, 'items' => 1, 'GrandTotal' => 0, 'statut' => 'completed', 'approval_status' => 'approved', 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"];
                    $TRD[] = ['id' => $nextTrD++, 'transfer_id' => $tid, 'product_id' => $pid, 'cost' => 0, 'purchase_unit_id' => 1, 'quantity' => $e['q'], 'total' => 0, 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"];
                    break;
                case 'adjust':
                    if (! $e['add'] && $st[$w]['q'] < $e['q']) { break; }
                    $aid = $nextAdj++;
                    $AJ[] = ['id' => $aid, 'user_id' => 2, 'date' => $date, 'time' => '12:00:00', 'Ref' => 'LG-A-'.$aid, 'warehouse_id' => $w, 'items' => 1, 'notes' => 'lg', 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"];
                    $AJD[] = ['id' => $nextAdjD++, 'product_id' => $pid, 'adjustment_id' => $aid, 'quantity' => $e['q'], 'type' => $e['add'] ? 'add' : 'sub', 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"];
                    if ($e['add']) { $c = $master[$pid]; $s = &$st[$w];
                        if ($s['q'] <= 0) { $nq = $s['q'] + $e['q']; if ($nq > 0) { $s['avg'] = $c; } elseif ($s['avg'] <= 0) { $s['avg'] = $c; } $s['q'] = $nq; }
                        else { $s['avg'] = ($s['q'] * $s['avg'] + $e['q'] * $c) / ($s['q'] + $e['q']); $s['q'] += $e['q']; } unset($s);
                    } else { $expected['writeoff'] += $st[$w]['avg'] * $e['q']; $st[$w]['q'] -= $e['q']; }
                    break;
                case 'sret':
                    $cand = array_values(array_filter($soldLines[$w] ?? [], fn ($l) => $l[4] < $l[1]));
                    if (! $cand) { break; }
                    $k = mt_rand(0, count($cand) - 1); $line = $cand[$k]; $rq = mt_rand(1, $line[1] - $line[4]);
                    foreach ($soldLines[$w] as $ix => $l) { if ($l[0] === $line[0]) { $soldLines[$w][$ix][4] += $rq; } }
                    $u = $line[2]; $sd = &$st[$w];
                    if ($sd['q'] <= 0) { $nq = $sd['q'] + $rq; if ($nq > 0) { $sd['avg'] = $u; } elseif ($sd['avg'] <= 0) { $sd['avg'] = $u; } $sd['q'] = $nq; }
                    else { $sd['avg'] = ($sd['q'] * $sd['avg'] + $rq * $u) / ($sd['q'] + $rq); $sd['q'] += $rq; }
                    unset($sd);
                    $rid = $nextSR++; $sale_of = $line[0];
                    $SR[] = ['id' => $rid, 'user_id' => 2, 'date' => $date, 'Ref' => 'LG-R-'.$rid, 'sale_id' => 0 /* fixed below */, 'client_id' => 2, 'warehouse_id' => $w, 'GrandTotal' => 0, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'statut' => 'received', 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00", '_line' => $sale_of];
                    $lid = $nextSRD++;
                    $SRD[] = ['id' => $lid, 'sale_return_id' => $rid, 'product_id' => $pid, 'price' => 1, 'sale_unit_id' => 1, 'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_method' => '2', 'quantity' => $rq, 'total' => $rq, 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"];
                    $expected['ret'][$lid] = $u * $rq; $expected['month'][substr($date, 0, 7)] = ($expected['month'][substr($date, 0, 7)] ?? 0) - $u * $rq;
                    break;
                case 'pret':
                    $cand = array_values(array_filter($lastPurchase[$w] ?? [], fn ($p) => ! $p['box'] && $p['q'] >= 5));
                    if (! $cand) { break; }
                    $pp = $cand[count($cand) - 1]; $rq = min(4, (int) $pp['q']); if ($st[$w]['q'] < $rq) { break; }
                    $sd = &$st[$w]; $nq = $sd['q'] - $rq;
                    if ($nq > 0) { $na = ($sd['q'] * $sd['avg'] - $rq * $pp['unit']) / $nq; if ($na >= 0) { $sd['avg'] = $na; } } $sd['q'] = $nq; unset($sd);
                    $rid = $nextPR++;
                    $PR[] = ['id' => $rid, 'user_id' => 2, 'date' => $date, 'Ref' => 'LG-PR-'.$rid, 'purchase_id' => $pp['hid'], 'provider_id' => 1, 'warehouse_id' => $w, 'GrandTotal' => $rq * $pp['lineCost'], 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'statut' => 'completed', 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"];
                    $PRD[] = ['id' => $nextPRD++, 'purchase_return_id' => $rid, 'product_id' => $pid, 'cost' => $pp['lineCost'], 'purchase_unit_id' => 1, 'TaxNet' => $pp['tax'], 'tax_method' => $pp['tm'], 'discount' => $pp['disc'], 'discount_method' => $pp['dm'], 'quantity' => $rq, 'total' => $rq * $pp['lineCost'], 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"];
                    break;
                case 'damage':
                    if ($st[$w]['q'] < $e['q']) { break; }
                    $expected['writeoff'] += $st[$w]['avg'] * $e['q'];
                    $st[$w]['q'] -= $e['q']; $did2 = $nextDmg++;
                    $DM[] = ['id' => $did2, 'user_id' => 2, 'date' => $date, 'Ref' => 'LG-D-'.$did2, 'warehouse_id' => $w, 'items' => 1, 'time' => '12:00:00', 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"];
                    $DMD[] = ['id' => $nextDmgD++, 'product_id' => $pid, 'damage_id' => $did2, 'quantity' => $e['q'], 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"];
                    break;
            }
        }
    }
    if (! isset($snapDone[$pid])) { foreach ($WH as $w) { $snapValue += max(0, $st[$w]['q']) * $st[$w]['avg']; } $snapDone[$pid] = true; }
    foreach ($WH as $w) { $expected['bal'][$pid.':'.$w] = $st[$w]; }
    foreach ($WH as $w) {                                                    // shelf quantity = the books (+ imported opening stock lives here too)
        DB::table('product_warehouse')->insert(['product_id' => $pid, 'warehouse_id' => $w, 'product_variant_id' => null, 'qte' => round($st[$w]['q'], 3), 'manage_stock' => 1, 'created_at' => $nowTs, 'updated_at' => $nowTs]);
    }
    $expected['open'][$pid] = $opening;
}
// group sale lines into invoices of 1-3 lines per (date, warehouse); returns point at the invoice of their sale line
$byKey = []; foreach ($SLINES as $i => $l) { $byKey[$l['date'].'|'.$l['w']][] = $i; }
$SALES = []; $SD = []; $headerOf = [];
foreach ($byKey as $key => $idxs) {
    for ($o = 0; $o < count($idxs);) {
        $n = min(count($idxs) - $o, mt_rand(1, 3)); $sid = $nextSale++; $tot = 0.0;
        [$date, $w] = explode('|', $key);
        for ($j = 0; $j < $n; $j++) { $l = $SLINES[$idxs[$o + $j]]; $tot += $l['q'] * $l['price'];
            $SD[] = ['id' => $l['id'], 'sale_id' => $sid, 'date' => $date, 'product_id' => $l['pid'], 'quantity' => $l['q'], 'price' => $l['price'], 'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_method' => '2', 'total' => $l['q'] * $l['price'], 'sale_unit_id' => 1, 'pack_multiplier' => null, 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"];
            $headerOf[$l['id']] = $sid; }
        $SALES[] = ['id' => $sid, 'Ref' => 'LG-S-'.$sid, 'date' => $date, 'client_id' => 2, 'warehouse_id' => (int) $w, 'user_id' => 2, 'statut' => 'completed', 'GrandTotal' => $tot, 'TaxNet' => 0, 'shipping' => 0, 'discount' => 0, 'tax_rate' => 0, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"];
        $o += $n;
    }
}
foreach ($SR as $i => $r) { $SR[$i]['sale_id'] = $headerOf[$r['_line']]; unset($SR[$i]['_line']); }
$lap("generated ($NP products, $DAYS days) with independent books");

$ins = function (string $table, array $rows) { foreach (array_chunk($rows, 1500) as $c) { DB::table($table)->insert($c); } };
foreach ([['purchases', $P], ['purchase_details', $PD], ['sales', $SALES], ['sale_details', $SD], ['sale_returns', $SR], ['sale_return_details', $SRD],
    ['purchase_returns', $PR], ['purchase_return_details', $PRD], ['adjustments', $AJ], ['adjustment_details', $AJD], ['damages', $DM], ['damage_details', $DMD],
    ['transfers', $TR], ['transfer_details', $TRD]] as [$t, $rows]) { $ins($t, $rows); }
$lines = count($PD) + count($SD) + count($SRD) + count($PRD) + count($AJD) + count($DMD) + count($TRD);
$lap(sprintf('inserted %s document lines (%s sales lines, %s purchase lines, %s returns, %s other)', number_format($lines), number_format(count($SD)), number_format(count($PD)), number_format(count($SRD) + count($PRD)), number_format(count($AJD) + count($DMD) + count($TRD))));

// ---------------------------------------------------------------- legacy report timings (costing OFF) ----------------------------------------------
$u = Illuminate\Support\Facades\Auth::guard('api')->user();
$mkReq = function (array $q) use ($u) { $r = Illuminate\Http\Request::create('/api/x', 'GET', $q); $r->setUserResolver(fn () => $u); app()->instance('request', $r); return $r; };
$time = function (string $label, callable $f) { $s = microtime(true); $r = $f(); $t = microtime(true) - $s; printf("  [time] %-58s %6.2fs\n", $label, $t); return [$r, $t]; };
$from = '2029-01-01'; $to = '2030-12-31';
[$plLegacy, $tLegacyPL] = $time('legacy P&L (2 years)', fn () => json_decode(app(RC::class)->ProfitAndLoss($mkReq(['from' => $from, 'to' => $to]))->getContent(), true)['data']);
[$_, $tLegacyProfit] = $time('legacy Profit report by product', fn () => app(PRC::class)->index($mkReq(['from' => $from, 'to' => $to, 'limit' => -1]), 'product'));
$t0 = microtime(true);

// ---------------------------------------------------------------- switch costing ON and cost everything ---------------------------------------------
Svc::setMethod('moving_average'); Svc::forgetMethodCache();
$svc = new Svc;
$n = $svc->syncNewDocuments();
$tFirst = microtime(true) - $t0; $lap("FIRST-TIME costing of $n products (the one-off 'rebuild')");
$firstSync = microtime(true);
check('costing tables are filled', DB::table('inventory_cost_ledger')->count() > 0);

// 1. every sale line's stored cost == the independent books
$bad = 0; $checked = 0; $firstBad = null;
foreach (DB::table('inventory_cost_ledger')->where('source_type', 'sale')->where('source_id', '>=', min(array_keys($expected['sale'])))->orderBy('id')->cursor() as $l) {
    if (! isset($expected['sale'][$l->source_id])) { continue; }
    $checked++;
    if (abs(-$l->value_delta - $expected['sale'][$l->source_id]) > 0.01) { $bad++; $firstBad ??= [$l->source_id, -$l->value_delta, $expected['sale'][$l->source_id]]; }
}
check(sprintf('all %s sale lines: stored COGS == independent books (bad %d)', number_format($checked), $bad), $checked === count($expected['sale']) && $bad === 0, json_encode([$checked, count($expected['sale']), $firstBad]));
$badR = 0; foreach (DB::table('inventory_cost_ledger')->where('source_type', 'sale_return')->whereIn('source_id', array_keys($expected['ret']))->get() as $l) { if (abs($l->value_delta - $expected['ret'][$l->source_id]) > 0.01) { $badR++; } }
check(sprintf('all %d sale-return lines reverse the ORIGINAL sale cost (bad %d)', count($expected['ret']), $badR), DB::table('inventory_cost_ledger')->where('source_type', 'sale_return')->whereIn('source_id', array_keys($expected['ret']))->count() === count($expected['ret']) && $badR === 0);

// 2. every closing balance
$badB = 0; $firstB = null; $balRows = DB::table('inventory_cost_balances')->whereIn('product_id', array_values($pids))->get()->keyBy(fn ($b) => $b->product_id.':'.$b->warehouse_id);
foreach ($expected['bal'] as $k => $s) { $b = $balRows[$k] ?? null; if (! $b) { if (abs($s['q']) > 0.0005) { $badB++; $firstB ??= [$k, 'missing']; } continue; }
    if (abs($b->qty - $s['q']) > 0.001 || ($s['q'] > 0 && (abs($b->avg_cost - $s['avg']) > 0.0005 || abs($b->value - $s['q'] * $s['avg']) > 0.05))) { $badB++; $firstB ??= [$k, $b->qty, $b->avg_cost, $s]; } }
check(sprintf('all %d product/warehouse balances == independent books (bad %d)', count($expected['bal']), $badB), $badB === 0, json_encode($firstB));
check('no unexplained stock other than the imported opening stock', DB::table('inventory_cost_seeds')->whereIn('product_id', array_values($pids))->where('reason', '!=', 'opening_unexplained')->count() === 0, json_encode(DB::table('inventory_cost_seeds')->whereIn('product_id', array_values($pids))->where('reason', '!=', 'opening_unexplained')->limit(3)->get()));
$nOpen = count(array_filter($expected['open'])); check("imported opening stock became exactly $nOpen seeds", DB::table('inventory_cost_seeds')->whereIn('product_id', array_values($pids))->count() === $nOpen, (string) DB::table('inventory_cost_seeds')->whereIn('product_id', array_values($pids))->count());

// 3. reports
[$pl, $tPL] = $time('active P&L (2 years)', fn () => json_decode(app(RC::class)->ProfitAndLoss($mkReq(['from' => $from, 'to' => $to]))->getContent(), true)['data']);
$expCogs = array_sum($expected['sale']) - array_sum($expected['ret']);
check(sprintf('P&L 2-year COGS = independent books %.2f', $expCogs), $near($pl['product_cost_fifo'], $expCogs, 0.5) && $near($pl['averagecost'], $expCogs, 0.5), json_encode([$pl['product_cost_fifo'], $pl['averagecost']]));
foreach ($expected['month'] as $m => $cg) { if (! in_array($m, ['2029-03', '2029-11', '2030-06', '2030-12'], true)) { continue; }
    $r = json_decode(app(RC::class)->ProfitAndLoss($mkReq(['from' => "$m-01", 'to' => date('Y-m-t', strtotime("$m-01"))]))->getContent(), true)['data'];
    check("P&L $m COGS = independent books ".round($cg, 2), $near($r['product_cost_fifo'], $cg, 0.05), (string) $r['product_cost_fifo']); }
check('inventory write-off (damage + adjustment decreases) == independent books', $near($pl['inventory_writeoff_sum'], $expected['writeoff'], 0.5), json_encode([$pl['inventory_writeoff_sum'], $expected['writeoff']]));
check('profit = revenue - COGS - expenses - writeoff (+service) on the same data', $near($pl['profit_fifo'], $pl['total_revenue'] - $pl['product_cost_fifo'] - $pl['expenses_sum'] - $pl['inventory_writeoff_sum'] + $pl['service_profit'], 0.05));
printf("      (legacy P&L would have said COGS %.2f; the books say %.2f)\n", $plLegacy['product_cost_fifo'], $expCogs);

[$prResp, $tProfit] = $time('active Profit report by product', fn () => json_decode(app(PRC::class)->index($mkReq(['from' => $from, 'to' => $to, 'limit' => -1]), 'product')->getContent(), true));
check('Profit report COGS == P&L COGS == books', $near($prResp['kpis']['cost'], $expCogs, 0.5) && $near($prResp['kpis']['cost'], $pl['product_cost_fifo'], 0.5), json_encode($prResp['kpis']));
check('Profit report: sum of product rows == KPI', $near(collect($prResp['rows'])->sum('cost'), $prResp['kpis']['cost'], 0.5));
foreach (['warehouse', 'date', 'category', 'customer'] as $dim) { $r = json_decode(app(PRC::class)->index($mkReq(['from' => $from, 'to' => $to, 'limit' => -1]), $dim)->getContent(), true);
    check("Profit report by $dim: rows add up to the same COGS", $near(collect($r['rows'])->sum('cost'), $expCogs, 0.5) && $near($r['kpis']['cost'], $expCogs, 0.5), json_encode($r['kpis'])); }

// stock value everywhere == books
$expValue = 0.0; foreach ($expected['bal'] as $s) { if ($s['q'] > 0) { $expValue += $s['q'] * $s['avg']; } }
$allWh = DB::table('warehouses')->pluck('id')->all();
$dash = (new DASH)->StockValue(0, $allWh);
$sv = CostingReader::stockValue(null, $allWh, array_values($pids));
check(sprintf('costed stock value of these products = books %.2f', $expValue), $near($sv['value'], $expValue, 0.5), json_encode($sv));
$others = (float) DB::table('inventory_cost_balances')->whereNotIn('product_id', array_values($pids))->where('qty', '>', 0)->sum(DB::raw('qty * avg_cost'));
check('Dashboard stock value == books + untouched base stock', $near($dash['by_cost'], $expValue + $others, 0.5), json_encode([$dash['by_cost'], $expValue + $others]));
$rq = $mkReq(['limit' => -1, 'search' => 'LG-']);
[$ivr, $tIV] = $time('active stock_inventory_valuation (all rows)', fn () => json_decode(app(RC::class)->stock_inventory_valuation($rq)->getContent(), true));
check('stock_inventory_valuation total (positive rows) == books', $near(collect($ivr['reports'])->sum(fn ($r) => $r['current_quantity'] > 0 ? $r['stock_value_cost'] : 0), $expValue, 1.0), (string) collect($ivr['reports'])->sum('stock_value_cost'));
$rq = $mkReq(['limit' => 10, 'search' => 'LG-']);
$ivs = json_decode(app(RC::class)->inventory_valuation_summary($rq)->getContent(), true);
$expAll = 0.0; foreach ($expected['bal'] as $s) { $expAll += $s['q'] * $s['avg']; }
check('inventory_valuation_summary asset value == books (all rows incl. negative)', $near($ivs['summary']['asset_value'], $expAll, 1.0), json_encode([$ivs['summary']['asset_value'], $expAll]));
$an = json_decode(app(RC::class)->analyticsSummary($mkReq(['from' => '2030-01-01', 'to' => '2030-12-31']))->getContent(), true); $an = $an['data'] ?? $an;
check('analytics: OPENING stock at cost (2030-01-01) = book value at 2029-12-31 (+ untouched base stock)', $near($an['opening_stock_purchase_price'], $snapValue + $others, 1.0), json_encode([$an['opening_stock_purchase_price'], $snapValue + $others]));
check('analytics: CLOSING stock at cost (2030-12-31) = books', $near($an['closing_stock_purchase_price'], $expValue + $others, 1.0), json_encode([$an['closing_stock_purchase_price'], $expValue + $others]));
[$t, $tToday] = $time('Today summary', fn () => json_decode(app(App\Http\Controllers\TodaySummaryController::class)->index($mkReq([]))->getContent(), true));
check('Today summary stock at cost == Dashboard stock value', $near($t['stock']['at_cost'], $dash['by_cost'], 2.0), json_encode([$t['stock']['at_cost'], $dash['by_cost']]));
[$_, $tDead] = $time('dead stock report', fn () => app(RC::class)->deadStock($mkReq(['limit' => 10])));
[$_, $tNeg] = $time('negative stock report', fn () => app(RC::class)->negative_stock_report($mkReq(['limit' => 10])));
[$_, $tWh] = $time('warehouse stock report', fn () => app(RC::class)->Warhouse_Count_Stock($mkReq([])));

// 4. the cost of staying fresh
[$_, $tIdle] = $time('ensureFresh() when nothing changed (id marks only)', fn () => Svc::ensureFresh(3600));
[$ver, $tVerify] = $time('full fingerprint verification of ALL products', fn () => (new Svc)->verify(true));
check('fingerprint verification finds nothing to fix', $ver['dirty'] === [], json_encode(array_slice($ver['dirty'], 0, 5)));
mkSale([[ 'product_id' => $pids[1], 'quantity' => 1, 'Unit_price' => 30, 'tax_percent' => 0, 'tax_method' => '1', 'subtotal' => 30, 'discount' => 0, 'discount_Method' => '2', 'product_variant_id' => null, 'serial_numbers' => [], 'sale_unit_id' => 1]], ['date' => date('Y-m-d'), 'warehouse_id' => 1]);
[$_, $tNew] = $time('a NEW sale through the app is costed on the next read', fn () => Svc::ensureFresh(3600));
check('the new sale got its ledger row', DB::table('inventory_cost_ledger')->where('source_type', 'sale')->where('product_id', $pids[1])->where('occurred_at', '>=', date('Y-m-d').' 00:00:00')->exists());
// edit an OLD purchase: only that product is re-costed, its later sales are corrected and logged
$victim = DB::table('purchase_details')->whereIn('product_id', [$pids[2]])->orderBy('id')->first();
DB::table('purchase_details')->where('id', $victim->id)->update(['cost' => $victim->cost + 3]);   // a raw edit: no controller, no updated_at bump
$corr0 = DB::table('inventory_cost_corrections')->count();
[$_, $tEdit] = $time('an OLD purchase cost edited behind the app: found + re-costed', fn () => (new Svc)->verify(true));
check('the edit was detected and only that product re-costed', $_ !== null, '');
check('corrections were logged for the sales it re-costed', DB::table('inventory_cost_corrections')->count() > $corr0, (string) DB::table('inventory_cost_corrections')->count());

// 5. audit stability: master cost for ALL products changed
$plBefore = json_decode(app(RC::class)->ProfitAndLoss($mkReq(['from' => '2029-01-01', 'to' => '2029-12-31']))->getContent(), true)['data'];
DB::table('products')->whereIn('id', array_values($pids))->update(['cost' => 777]);
$pl2 = json_decode(app(RC::class)->ProfitAndLoss($mkReq(['from' => '2029-01-01', 'to' => '2029-12-31']))->getContent(), true)['data'];
check('all master costs -> 777: the 2029 P&L COGS did not move', $near($pl2['product_cost_fifo'], $plBefore['product_cost_fifo'], 0.005), json_encode([$plBefore['product_cost_fifo'], $pl2['product_cost_fifo']]));

printf("\n  summary: %s lines | first-time costing %.1fs | idle refresh %.3fs | new-sale refresh %.3fs | full verify %.2fs | P&L legacy/active %.2fs/%.2fs | profit report legacy/active %.2fs/%.2fs\n",
    number_format($lines), $tFirst, $tIdle, $tNew, $tVerify, $tLegacyPL, $tPL, $tLegacyProfit, $tProfit);
check('performance: ensureFresh with no change is instant (<0.5s)', $tIdle < 0.5, (string) $tIdle);
check('performance: a new sale is costed within 2s', $tNew < 2.0, (string) $tNew);
check('performance: profit report and P&L stay under 10s on this size', $tProfit < 10 && $tPL < 10, "$tProfit / $tPL");
finish('Costing on a large data set');
