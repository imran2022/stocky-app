<?php

/**
 * Build D2 no-dependency Product Analytics correctness regression gate.
 *
 * Run with: php tests/Regression/build_d2_product_analytics.php
 * Protects the exact rolling 30-calendar-day comparison and the finalized
 * Sale Return population used by Product Return Rate.
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$contains = static function (string $haystack, string $needle, string $message) use ($assert): void {
    $assert(str_contains($haystack, $needle), $message);
};
$notContains = static function (string $haystack, string $needle, string $message) use ($assert): void {
    $assert(! str_contains($haystack, $needle), $message);
};

$root = dirname(__DIR__, 2);
$controller = file_get_contents($root.'/app/Http/Controllers/ProductsController.php');
$service = file_get_contents($root.'/app/Services/Custom/ProductInsightService.php');
$returnController = file_get_contents($root.'/app/Http/Controllers/SalesReturnController.php');

$assert($controller !== false, 'ProductsController.php must be readable.');
$assert($service !== false, 'ProductInsightService.php must be readable.');
$assert($returnController !== false, 'SalesReturnController.php must be readable.');

if ($service !== false) {
    // Exactly 30 calendar dates including today: today-29 through tomorrow
    // exclusive. Previous comparison is the immediately preceding 30 dates.
    $contains($service, 'public function rolling30DayWindows(): array', 'One shared window helper must own Product 30-day boundaries.');
    $contains($service, "subDays(29)->toDateString()", 'Current Sold (30d) window must start 29 dates before today.');
    $contains($service, "addDay()->toDateString()", 'Current Sold (30d) window must end tomorrow, exclusive.');
    $contains($service, "subDays(59)->toDateString()", 'Previous window must start 59 dates before today.');
    $contains($service, "'previous_end_exclusive' => \$currentStart", 'Previous window must end exactly where the current window begins.');
    $contains($service, 'sd.date >= ? AND sd.date < ?', 'Both Product sale windows must use half-open date intervals.');
    $notContains($service, 'sd.date BETWEEN ? AND ?', 'D2 must not use inclusive BETWEEN for the previous comparison window.');

    // Return Rate numerator: only the status that actually re-stocks inventory.
    $contains($service, "->where('sr.statut', 'received')", 'Return Rate must count only received/finalized Sale Returns.');
    $contains($service, "->whereNull('sr.deleted_at')", 'Return Rate must continue excluding deleted Sale Returns.');
    $contains($service, "'sr.warehouse_id'", 'Return Rate must retain D1 warehouse visibility.');

    // G1 is the unit/multi-pack redesign, layered on top of D2 without
    // touching D2's own contracts (windowing, return status/scope above).
    $contains($service, 'UnitQuantityResolver::baseQuantityExpression(', 'G1 base-unit conversion must be present via the isolated resolver.');

    // Build C performance contract stays bounded.
    $executedQueryCount = preg_match_all('/->get\s*\(/', $service);
    $assert($executedQueryCount === 4, "D2 must retain four executed set-based metric queries; found {$executedQueryCount}.");
}

if ($controller !== false) {
    $contains($controller, '$productInsightService = app(ProductInsightService::class);', 'ProductsController must share the Product Insight service instance.');
    $contains($controller, '$salesWindows = $productInsightService->rolling30DayWindows();', 'Sold (30d) sorting must use the same window helper as the displayed metric.');
    $contains($controller, "->where('sale_details.date', '>=', \$salesWindows['current_start'])", 'Sold sorting must apply current window start.');
    $contains($controller, "->where('sale_details.date', '<', \$salesWindows['current_end_exclusive'])", 'Sold sorting must apply the exclusive current window end.');
}

if ($returnController !== false) {
    // Verify the chosen finalized status is not invented by the Product page:
    // Stocky's own Sale Return workflow changes stock only for 'received'.
    $contains($returnController, "if (\$order->statut == 'received')", 'Sale Return creation must confirm received is a stock-affecting finalized status.');
    $contains($returnController, "if (\$current_SaleReturn->statut == 'received')", 'Sale Return update/delete flow must confirm received is the existing stock-affecting status.');
}

// Pure calendar sanity check, independent of Laravel/Carbon dependencies.
$anchor = new DateTimeImmutable('2026-09-11 00:00:00');
$currentStart = $anchor->modify('-29 days');
$currentEndExclusive = $anchor->modify('+1 day');
$previousStart = $anchor->modify('-59 days');
$previousEndExclusive = $currentStart;
$assert($currentStart->format('Y-m-d') === '2026-08-13', 'Sep 11 current 30-day window must begin Aug 13.');
$assert($currentEndExclusive->format('Y-m-d') === '2026-09-12', 'Sep 11 current window must end Sep 12 exclusive.');
$assert($previousStart->format('Y-m-d') === '2026-07-14', 'Previous 30-day window must begin Jul 14.');
$assert($previousEndExclusive->format('Y-m-d') === '2026-08-13', 'Previous 30-day window must end Aug 13 exclusive.');
$assert((int) $currentStart->diff($currentEndExclusive)->format('%a') === 30, 'Current interval must contain exactly 30 calendar days.');
$assert((int) $previousStart->diff($previousEndExclusive)->format('%a') === 30, 'Previous interval must contain exactly 30 calendar days.');

if ($failures) {
    fwrite(STDERR, "Build D2 Product Analytics gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build D2 Product Analytics gate: PASS (exact rolling windows + finalized returns protected).\n";
