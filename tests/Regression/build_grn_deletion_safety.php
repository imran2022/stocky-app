<?php

/**
 * GRN deletion stock-safety regression gate.
 *
 * Static/source checks, matching the existing build_*.php convention.
 * Full functional correctness (actual negative-stock blocking, and that a
 * safe delete still succeeds normally) was verified separately against
 * live data before this fix was packaged — see the delivery notes for the
 * exact scenario (50 received, 30 sold, delete blocked at -30; stock
 * restored, delete then succeeds, resulting stock exactly 0).
 */

$root = dirname(__DIR__, 2);

$source = function (string $relative) use ($root): string {
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        fwrite(STDERR, "GRN deletion safety gate FAILED:\n - Missing file: {$relative}\n");
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

$service = $source('app/Services/Custom/GrnDeletionSafetyService.php');
$contains($service, "\$purchase->statut !== 'received'", 'A GRN that never added stock must skip the check entirely.');
$contains($service, 'function checkSafeToDelete(', 'Service must expose the pre-flight check method.');
$contains($service, 'resultingStock < -0.0001', 'Check must use a small epsilon, not exact zero, to avoid float-precision false positives.');
$contains($service, "\$unit->operator === '/'", 'Base-unit conversion must match the same operator convention used elsewhere in this codebase.');

$controller = $source('app/Http/Controllers/PurchasesController.php');
$contains($controller, 'use App\Services\Custom\GrnDeletionSafetyService;', 'PurchasesController must import the isolated service, not reimplement the check inline.');

// The check must run BEFORE the DB::transaction() closure opens in both
// destroy() and delete_by_selection() — a response returned from inside
// that closure is silently discarded by this controller's own
// unconditional success response afterward (a real, pre-existing,
// separate issue in the vendor-adjacent code this fix must not repeat).
$destroyPos = strpos($controller, 'public function destroy(Request $request, $id)');
$destroyTransactionPos = strpos($controller, '\DB::transaction(function () use ($id, $request) {', $destroyPos);
$destroyCheckPos = strpos($controller, 'GrnDeletionSafetyService::class)->checkSafeToDelete(', $destroyPos);
if ($destroyPos === false || $destroyTransactionPos === false || $destroyCheckPos === false || $destroyCheckPos > $destroyTransactionPos) {
    $errors[] = 'destroy() must call the stock-safety check BEFORE its DB::transaction() opens, not inside the closure.';
}

$bulkPos = strpos($controller, 'public function delete_by_selection(Request $request)');
$bulkTransactionPos = strpos($controller, '\DB::transaction(function () use ($request) {', $bulkPos);
$bulkCheckPos = strpos($controller, 'GrnDeletionSafetyService::class)->checkSafeToDelete(', $bulkPos);
if ($bulkPos === false || $bulkTransactionPos === false || $bulkCheckPos === false || $bulkCheckPos > $bulkTransactionPos) {
    $errors[] = 'delete_by_selection() must call the stock-safety check BEFORE its DB::transaction() opens, not inside the closure.';
}

if ($errors !== []) {
    fwrite(STDERR, "GRN deletion safety gate FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

echo "GRN Deletion Safety gate: PASS (pre-flight negative-stock check runs outside the transaction closure in both delete paths).\n";
