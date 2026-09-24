<?php
// Unit tests for the pure Moving Weighted Average engine (no database, no framework).
// Run: php tests/Regression/unit_costing_engine.php
require __DIR__.'/../../app/Services/Costing/MovingAverageEngine.php';

use App\Services\Costing\MovingAverageEngine as E;

$fails = [];
function check($label, $cond, $detail = '')
{
    global $fails;
    echo ($cond ? '  PASS  ' : '  FAIL  ').$label.($cond ? '' : "  [$detail]")."\n";
    if (! $cond) { $fails[] = $label; }
}
function near($a, $b, $eps = 0.005) { return abs($a - $b) <= $eps; }
function mv($type, $id, $wh, $qty, $cost = null, array $x = []) { return array_merge(['type' => $type, 'source_id' => $id, 'warehouse_id' => $wh, 'qty' => $qty, 'unit_cost' => $cost, 'estimated' => false, 'reference_id' => $id], $x); }
function byId($rows, $type, $id) { foreach ($rows as $r) { if ($r['source_type'] === $type && $r['source_id'] === $id) { return $r; } } return null; }

// ---- 1. the scenario from the review: 50@100 opening, GRN 4@120, GRN 55@110, sell 20@150 ---------------------------------
$r = E::replay([
    mv('seed', 1, 1, 50, 100, ['estimated' => true]),
    mv('purchase', 10, 1, 4, 120),
    mv('purchase', 11, 1, 55, 110),
    mv('sale', 20, 1, -20),
]);
$sale = byId($r['rows'], 'sale', 20);
check('avg after receipts = 11530/109 = 105.7798', near($r['rows'][2]['avg_cost'], 105.7798, 0.0001), json_encode($r['rows'][2]));
check('sale COGS = 20 x 105.78 = 2115.60', near(-$sale['value_delta'], 2115.60, 0.01), (string) $sale['value_delta']);
check('gross profit = 3000 - 2115.60 = 884.40', near(3000 + $sale['value_delta'], 884.40, 0.01));
check('remaining qty 89', near($r['balances'][1]['qty'], 89, 0.0001));
check('remaining value = 9414.40', near($r['balances'][1]['value'], 9414.40, 0.01), (string) $r['balances'][1]['value']);
check('average is unchanged by the sale', near($sale['avg_cost'], 105.7798, 0.0001));
check('opening seed is flagged estimated, so the sale is too', $sale['is_estimated'] === true);

// ---- 2. all-actual costs are NOT flagged estimated ---------------------------------------------------------------------
$r = E::replay([mv('purchase', 1, 1, 10, 10), mv('sale', 2, 1, -3)]);
check('purchase-only history is not estimated', byId($r['rows'], 'sale', 2)['is_estimated'] === false);

// ---- 3. true MOVING average: buy, sell, buy again ----------------------------------------------------------------------
$r = E::replay([mv('purchase', 1, 1, 10, 10), mv('sale', 2, 1, -5), mv('purchase', 3, 1, 10, 20), mv('sale', 4, 1, -5)]);
check('first sale costs 10 each = 50', near(-byId($r['rows'], 'sale', 2)['value_delta'], 50));
check('avg after 2nd buy = (5x10 + 10x20)/15 = 16.6667', near(byId($r['rows'], 'purchase', 3)['avg_cost'], 16.6667, 0.0001));
check('second sale costs 5 x 16.6667 = 83.33 (sold units are NOT averaged in)', near(-byId($r['rows'], 'sale', 4)['value_delta'], 83.33, 0.01));
check('closing value = 100 + 200 - 50 - 83.33 = 166.67 (10 units)', near($r['balances'][1]['value'], 166.67, 0.01), (string) $r['balances'][1]['value']);

// ---- 4. value conservation: sum(in) - sum(out) = closing value ---------------------------------------------------------
$mvs = [mv('purchase', 1, 1, 100, 7.5), mv('purchase', 2, 1, 40, 9.25), mv('sale', 3, 1, -30), mv('adjustment', 4, 1, -5), mv('damage', 5, 1, -2),
    mv('purchase', 6, 1, 60, 8.1), mv('sale', 7, 1, -80), mv('adjustment', 8, 1, 10, 8.0, ['estimated' => true])];
$r = E::replay($mvs);
$net = array_sum(array_column($r['rows'], 'value_delta'));
check('conservation: sum(value_delta) = closing value', near($net, $r['balances'][1]['value'], 0.0001), $net.' vs '.$r['balances'][1]['value']);

// ---- 5. sale return comes back at the ORIGINAL sale cost, not today's average ------------------------------------------
$r = E::replay([mv('purchase', 1, 1, 10, 100), mv('sale', 2, 1, -4), mv('purchase', 3, 1, 10, 200),
    mv('sale_return', 4, 1, 4, null, ['orig_sources' => [2]])]);
$ret = byId($r['rows'], 'sale_return', 4);
check('return unit cost = original 100 (not the 150 average)', near($ret['unit_cost'], 100), (string) $ret['unit_cost']);
check('return value_delta = +400 (exactly reverses the sale COGS)', near($ret['value_delta'], 400));
check('closing qty 20', near($r['balances'][1]['qty'], 20));
check('closing value = 6x100 + 10x200 + 4x100 = 3000', near($r['balances'][1]['value'], 3000, 0.01), (string) $r['balances'][1]['value']);

// ---- 6. sale return with no traceable original falls back to the running average ---------------------------------------
$r = E::replay([mv('purchase', 1, 1, 10, 50), mv('sale_return', 2, 1, 2, null, ['orig_sources' => []])]);
check('unlinked return valued at average 50', near(byId($r['rows'], 'sale_return', 2)['unit_cost'], 50));

// ---- 7. purchase return leaves at the cost on the return, average is re-derived ----------------------------------------
$r = E::replay([mv('purchase', 1, 1, 50, 100), mv('purchase', 2, 1, 4, 120), mv('purchase_return', 3, 1, -4, 120)]);
check('after returning the 4@120 the average is back to 100', near($r['balances'][1]['avg_cost'], 100, 0.0001), (string) $r['balances'][1]['avg_cost']);
check('closing value 5000', near($r['balances'][1]['value'], 5000, 0.01));
$r = E::replay([mv('purchase', 1, 1, 50, 100), mv('purchase', 2, 1, 50, 200), mv('purchase_return', 3, 1, -10, 100)]);
check('returning cheap goods raises the average: (15000-1000)/90 = 155.56', near($r['balances'][1]['avg_cost'], 155.5556, 0.001), (string) $r['balances'][1]['avg_cost']);

// ---- 8. transfers: cost follows the goods ------------------------------------------------------------------------------
$r = E::replay([mv('purchase', 1, 1, 10, 100), mv('purchase', 2, 2, 10, 200),
    mv('transfer_out', 3, 1, -4), mv('transfer_in', 3, 2, 4, null, ['pair_source_id' => 3])]);
check('destination receives at the SOURCE average (100)', near(byId($r['rows'], 'transfer_in', 3)['unit_cost'], 100));
check('destination avg = (10x200 + 4x100)/14 = 171.43', near($r['balances'][2]['avg_cost'], 171.4286, 0.001), (string) $r['balances'][2]['avg_cost']);
check('source keeps avg 100, qty 6', near($r['balances'][1]['avg_cost'], 100) && near($r['balances'][1]['qty'], 6));
check('company value conserved across the transfer', near($r['balances'][1]['value'] + $r['balances'][2]['value'], 1000 + 2000, 0.01));

// ---- 9. negative stock ---------------------------------------------------------------------------------------------------
$r = E::replay([mv('purchase', 1, 1, 5, 10), mv('sale', 2, 1, -8), mv('purchase', 3, 1, 10, 20), mv('sale', 4, 1, -1)]);
check('oversold sale still costed at last average 10', near(-byId($r['rows'], 'sale', 2)['value_delta'], 80));
check('balance is -3 after oversell', near(byId($r['rows'], 'sale', 2)['balance_qty'], -3));
check('receipt into negative stock resets average to the receipt cost', near(byId($r['rows'], 'purchase', 3)['avg_cost'], 20), (string) byId($r['rows'], 'purchase', 3)['avg_cost']);
check('next sale costed at 20', near(-byId($r['rows'], 'sale', 4)['value_delta'], 20));

// ---- 10. no cost basis at all is flagged, never silently zero -------------------------------------------------------------
$r = E::replay([mv('sale', 1, 1, -1)]);
check('sale with no cost history is flagged estimated', byId($r['rows'], 'sale', 1)['is_estimated'] === true);

// ---- 11. estimated share fades as real purchases dilute it ------------------------------------------------------------------
$r = E::replay([mv('seed', 1, 1, 10, 100, ['estimated' => true]), mv('sale', 2, 1, -10), mv('purchase', 3, 1, 10, 100), mv('sale', 4, 1, -5)]);
check('after the estimated stock is fully sold, later sales are actual', byId($r['rows'], 'sale', 4)['is_estimated'] === false);

// ---- 12. warehouses are independent -------------------------------------------------------------------------------------------
$r = E::replay([mv('purchase', 1, 1, 10, 100), mv('purchase', 2, 2, 10, 120), mv('sale', 3, 1, -5), mv('sale', 4, 2, -5)]);
check('warehouse 1 sells at 100, warehouse 2 at 120', near(-byId($r['rows'], 'sale', 3)['value_delta'], 500) && near(-byId($r['rows'], 'sale', 4)['value_delta'], 600));

// ---- 13. fractional base units (Box of 12 bought, pieces sold) ---------------------------------------------------------------
$r = E::replay([mv('purchase', 1, 1, 24, 10.0), mv('sale', 2, 1, -18)]);
check('18 pieces at 10 = 180', near(-byId($r['rows'], 'sale', 2)['value_delta'], 180));

// ---- 14. determinism: same input, same output ---------------------------------------------------------------------------------
check('replay is deterministic', E::replay($mvs) === E::replay($mvs));

echo count($fails) === 0 ? "\nUnit costing engine: PASS\n" : "\nUnit costing engine: FAIL (".count($fails).'): '.implode('; ', $fails)."\n";
exit(count($fails) === 0 ? 0 : 1);
