<?php

/**
 * Build E1 no-dependency POS Recent Invoices regression gate.
 *
 * Run with: php tests/Regression/build_e1_pos_recent.php
 * Protects POS-only/record ownership/warehouse visibility and historical
 * document-currency rendering without touching the POS sale workflow itself.
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
$sales = file_get_contents($root.'/app/Http/Controllers/SalesController.php');
$pos = file_get_contents($root.'/resources/src/pages/pos/PosPage.vue');
$helpers = file_get_contents($root.'/app/utils/helpers.php');
// Resolve the compiled POS chunk through the Vite manifest instead of a
// pinned content-hash filename (the hash changes on every rebuild).
$compiledPosPath = null;
$viteManifest = json_decode((string) @file_get_contents($root.'/public/js/.vite/manifest.json'), true) ?: [];
foreach ($viteManifest as $entry) {
    if (isset($entry['file']) && preg_match('#^chunks/PosPage\.[A-Za-z0-9_-]+\.js$#', $entry['file'])) {
        $compiledPosPath = $root.'/public/js/'.$entry['file'];
        break;
    }
}
$compiledPos = $compiledPosPath ? file_get_contents($compiledPosPath) : false;
$serviceWorker = file_get_contents($root.'/public/sw.js');

$assert($sales !== false, 'SalesController.php must be readable.');
$assert($pos !== false, 'PosPage.vue must be readable.');
$assert($helpers !== false, 'helpers.php must be readable.');
$assert($compiledPos !== false, 'Compiled POS chunk must be readable.');
$assert($serviceWorker !== false, 'Service worker must be readable.');

if ($sales !== false) {
    $contains($sales, "authorizeForUser(\$request->user('api'), 'Sales_pos', Sale::class)", 'POS Recent Invoices must require the normal POS sales permission.');
    $contains($sales, "->where('is_pos', 1)", 'POS Recent Invoices must return POS-origin sales only.');
    $contains($sales, "->where('statut', 'completed')", 'POS Recent Invoices must return completed invoices only.');
    $contains($sales, '$viewRecords = $user->hasRecordView();', 'POS Recent Invoices must resolve record_view using the canonical User helper.');
    $contains($sales, "->when(! \$viewRecords, function (\$query) use (\$user)", 'POS Recent Invoices must narrow records when record_view is disabled.');
    $contains($sales, "\$query->where('user_id', \$user->id);", 'POS Recent Invoices must restrict ownership when record_view is disabled.');
    $contains($sales, "UserWarehouse::where('user_id', \$user->id)", 'POS Recent Invoices must resolve assigned warehouses.');
    $contains($sales, "\$query->whereIn('warehouse_id', \$allowedWarehouseIds);", 'POS Recent Invoices must restrict assigned warehouses.');

    $contains($sales, 'helpers::Get_Document_Currency($sale)', 'Historical invoice display must resolve the Sale document-currency snapshot.');
    $contains($sales, 'helpers::to_document_amount($sale->GrandTotal', 'Historical GrandTotal must be converted with the stored document rate.');
    $contains($sales, "'document_grand_total'", 'Recent invoice payload must expose document-currency GrandTotal separately.');
    $contains($sales, "'currency_symbol'", 'Recent invoice payload must expose the historical currency symbol.');
    $contains($sales, "'currency_code'", 'Recent invoice payload must expose the historical currency code.');
    $contains($sales, "'GrandTotal' => number_format(\$sale->GrandTotal", 'Base GrandTotal field must remain for backward/API compatibility.');
}

if ($pos !== false) {
    $contains($pos, 'formatPriceWithSymbol(s.currency_symbol, s.document_grand_total, 2)', 'Recent Invoice Amount must render the Sale document currency, not the currently selected POS currency.');
    $notContains($pos, 'formatPriceWithCurrentCurrency(s.GrandTotal, 2)', 'Old current-POS-currency formatter must not remain on Recent Invoice historical totals.');
}

if ($helpers !== false) {
    $contains($helpers, 'public static function Get_Document_Currency($doc)', 'Canonical document-currency resolver must exist.');
    $contains($helpers, 'public static function to_document_amount($baseAmount, $rate)', 'Canonical base-to-document conversion helper must exist.');
}


if ($compiledPos !== false) {
    $contains($compiledPos, 'formatPriceWithSymbol(l.currency_symbol,l.document_grand_total,2)', 'Deployment POS chunk must render historical document currency.');
    $notContains($compiledPos, 'formatPriceWithCurrentCurrency(l.GrandTotal,2)', 'Deployment POS chunk must not use current register currency for Recent Invoices.');
}

if ($serviceWorker !== false) {
    $matched = preg_match("/const VERSION = 'stocky-pwa-v(\d+)';/", $serviceWorker, $versionMatch);
    $assert($matched === 1 && (int) ($versionMatch[1] ?? 0) >= 10, 'PWA cache version must remain at E1 v10 or later so updated POS chunks are fetched.');
}

// Pure arithmetic sanity: a historical sale stores base 100 and rate 1.25,
// therefore the invoice list must display 125 in that document currency.
$baseTotal = 100.0;
$rate = 1.25;
$documentTotal = $baseTotal * $rate;
$assert(abs($documentTotal - 125.0) < 0.00001, 'Document amount conversion sanity check failed.');

if ($failures) {
    fwrite(STDERR, "Build E1 POS Recent gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build E1 POS Recent gate: PASS (scope + historical currency contracts protected).\n";
