<?php

/**
 * Build A.1-safe no-dependency regression gate.
 *
 * This safe overlay deliberately preserves the known-good compiled frontend
 * assets from the user's merged build. It verifies only the server-side
 * document-currency fallback so base-currency Sales rows are not blank.
 *
 * Run with: php tests/Regression/build_a1_currency_backend.php
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

if ($failures) {
    fwrite(STDERR, "Build A.1-safe currency backend gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build A.1-safe currency backend gate: PASS.\n";
