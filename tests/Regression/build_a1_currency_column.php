<?php

/**
 * Build A.1 no-dependency regression gate.
 *
 * Run with: php tests/Regression/build_a1_currency_column.php
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

$root = dirname(__DIR__, 2);
$salesController = file_get_contents($root.'/app/Http/Controllers/SalesController.php');
$salesPage = file_get_contents($root.'/resources/src/pages/sales/Sales.vue');
$serviceWorker = file_get_contents($root.'/public/sw.js');

$contains(
    $salesController,
    '$documentCurrency = helpers::Get_Document_Currency($Sale);',
    'Sales list must resolve currency through Stocky document-currency helper.'
);
$contains(
    $salesController,
    '$item[\'currency_code\'] = strtoupper((string) ($documentCurrency[\'code\'] ?? \'\'));',
    'Sales list must return the resolved base/foreign currency code.'
);
$contains(
    $salesPage,
    "dataIndex: 'currency_code', key: 'currency_code', width: 90, align: 'center', defaultHidden: true",
    'Currency column must be available but hidden by default.'
);
$versionOk = false;
if (preg_match("/const VERSION = 'stocky-pwa-v(\d+)';/", $serviceWorker, $m)) {
    $versionOk = ((int) $m[1]) >= 10;
}
$assert(
    $versionOk,
    'A.1 deployment must bump the PWA cache namespace to v10 or later for the changed Sales chunk.'
);

if ($failures) {
    fwrite(STDERR, "Build A.1 regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build A.1 regression gate: PASS (currency column resolved + default hidden).\n";
