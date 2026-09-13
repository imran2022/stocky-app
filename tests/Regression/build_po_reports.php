<?php

/**
 * PO Fulfillment Stats + Price Variance Report regression gate.
 *
 * Static/source checks, matching the existing build_*.php convention.
 * Full functional correctness was verified separately against live data
 * before packaging — see the delivery notes for the exact scenarios
 * (an overdue PO correctly counted in stats and returned by the
 * overdue_only filter; a PO agreed at cost 10 received at cost 12
 * correctly producing a 20% variance row, and correctly excluded by a
 * 25% threshold filter).
 */

$root = dirname(__DIR__, 2);

$source = function (string $relative) use ($root): string {
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        fwrite(STDERR, "PO reports gate FAILED:\n - Missing file: {$relative}\n");
        exit(1);
    }

    return file_get_contents($path);
};

$errors = [];

$contains = function (string $haystack, string $needle, string $message) use (&$errors) {
    if (! str_contains($haystack, $needle)) {
        $errors[] = $message;
    }
};

$notContains = function (string $haystack, string $needle, string $message) use (&$errors) {
    if (str_contains($haystack, $needle)) {
        $errors[] = $message;
    }
};

$controller = $source('app/Http/Controllers/PurchaseOrderController.php');

// ----- Fulfillment stats -----
$contains($controller, "\$openStatuses = ['ordered', 'partially_received']", 'Fulfillment stats must define open statuses explicitly.');
$contains($controller, "'overdue_count' =>", 'index() must return an overdue_count stat.');
$contains($controller, "'overdue_value' =>", 'index() must return an overdue_value stat.');
$contains($controller, "\$request->boolean('overdue_only')", 'index() must support an overdue_only quick-filter.');
// Stats must be computed over the filtered-but-not-yet-paginated query
// (a clone of $query before ->offset()/->limit() are applied), not the
// current page's rows only.
$statsPos = strpos($controller, '$stats = [');
$paginatePos = strpos($controller, '->offset($offset)');
if ($statsPos === false || $paginatePos === false || $statsPos > $paginatePos) {
    $errors[] = 'Fulfillment stats must be computed before pagination is applied to $query, not after.';
}

// ----- Price Variance Report -----
$contains($controller, 'function priceVarianceReport(', 'Controller must expose the Price Variance Report endpoint.');
$contains($controller, "'purchase_order_details as pod', 'pod.id', '=', 'pd.purchase_order_detail_id'", 'Report must join on the PO-detail linkage, not product_id alone (which would compare unrelated lines).');
$contains($controller, '$poCost != 0.0 ? round(($variance / $poCost) * 100, 2) : null', 'Variance percent must guard against division by zero (PO cost of 0) rather than producing Inf/NaN.');
$contains($controller, "'line_id' => \$row->line_id", 'Report rows must carry a genuinely unique per-line id (purchase_detail.id) — grn_ref alone is not unique when a GRN has multiple lines.');
$contains($controller, 'abs($row[\'variance_percent\']) >= $threshold', 'The min_variance_percent filter must compare by absolute value (both over- and under-charges matter).');

// ----- Routes -----
$routes = $source('routes/api.php');
$contains($routes, "Route::get('purchase_orders_reports/price_variance', 'PurchaseOrderController@priceVarianceReport');", 'Price Variance Report route must be registered.');

// ----- Frontend -----
$poList = $source('resources/src/pages/purchase_orders/PurchaseOrders.vue');
$contains($poList, 'function filterOverdue()', 'PO list must implement the clickable overdue stat card.');
$contains($poList, 'function isOverdue(record)', 'PO list must implement the per-row overdue check.');
$contains($poList, 'const filterParams = () => (', 'filterParams must be a plain function, not computed() — see the matching note on PriceVarianceReport.vue below.');
$notContains($poList, 'const filterParams = computed(', 'filterParams must not be a computed() ref — useCrudTable calls params() directly as a function, which throws "params is not a function" for a computed ref (a real bug found via a user report: this crashed before any network request was built, so it appeared in DevTools as zero requests, not a visible failed one).');

$variancePage = $source('resources/src/pages/reports/PriceVarianceReport.vue');
$contains($variancePage, "row-key=\"line_id\"", 'Price Variance Report table must use the unique line_id as its row key, not grn_ref.');
$contains($variancePage, "rowsKey: 'rows'", 'Price Variance Report must consume the rows payload key.');
// Real bug found via a user report: useCrudTable's `params` option must be a
// plain function ("() => ({...})") — it's called directly as params() inside
// fetchRows(). Passing a computed() ref there throws "params is not a
// function" synchronously, before any network request is built, which is
// exactly why the bug produced zero requests in the browser's Network tab
// rather than a visible failed request.
$contains($variancePage, 'const filterParams = () => (', 'filterParams must be a plain function, not computed() — see build note.');
$notContains($variancePage, 'const filterParams = computed(', 'filterParams must not be a computed() ref — useCrudTable calls params() directly as a function.');

$router = $source('resources/src/router/index.js');
$contains($router, "path: 'reports/price-variance'", 'Router must register the Price Variance Report route.');

if ($errors !== []) {
    fwrite(STDERR, "PO reports gate FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

echo "PO Fulfillment Stats + Price Variance Report gate: PASS.\n";
