<?php

/**
 * Build E3 no-dependency Sales/POS Sales 5.8 parity regression gate.
 *
 * Run with: php tests/Regression/build_e3_pos_sales_parity.php
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
$sales = file_get_contents($root.'/resources/src/pages/sales/Sales.vue');
$posSales = file_get_contents($root.'/resources/src/pages/sales/PosSales.vue');

// Resolve compiled chunk paths from the manifest rather than hardcoding a
// build-specific hash — see the same fix in build_f_stock_lookup_cleanup.php
// and its rationale.
$manifestRaw = file_get_contents($root.'/public/js/.vite/manifest.json');
$manifestData = $manifestRaw !== false ? json_decode($manifestRaw, true) : null;
$compiledSales = false;
$compiledPosSales = false;
if ($salesChunk = $manifestData['resources/src/pages/sales/Sales.vue']['file'] ?? null) {
    $compiledSales = file_get_contents($root.'/public/js/'.$salesChunk);
}
if ($posSalesChunk = $manifestData['resources/src/pages/sales/PosSales.vue']['file'] ?? null) {
    $compiledPosSales = file_get_contents($root.'/public/js/'.$posSalesChunk);
}
$serviceWorker = file_get_contents($root.'/public/sw.js');
$controller = file_get_contents($root.'/app/Http/Controllers/SalesController.php');

foreach ([
    'Sales.vue' => $sales,
    'PosSales.vue' => $posSales,
    'compiled Sales chunk' => $compiledSales,
    'compiled POS Sales chunk' => $compiledPosSales,
    'service worker' => $serviceWorker,
    'SalesController.php' => $controller,
] as $name => $contents) {
    $assert($contents !== false, "$name must be readable.");
}

if ($sales !== false) {
    $contains($sales, "{ title: t('Seller'), dataIndex: 'seller_name', key: 'seller_name' }", 'Normal Sales must expose the Stocky 5.8 Seller column.');
    $contains($sales, "dataIndex: 'currency_code', key: 'currency_code', width: 90, align: 'center', defaultHidden: true", 'Normal Sales Currency must remain available but start hidden.');
}

if ($posSales !== false) {
    $contains($posSales, "{ title: t('Seller'), dataIndex: 'seller_name', key: 'seller_name' }", 'POS Sales must carry the shared Seller column.');
    $contains($posSales, '...(auth.multiCurrencyEnabled', 'POS Sales Currency must respect the Stocky multi-currency feature flag.');
    $contains($posSales, "dataIndex: 'currency_code', key: 'currency_code', width: 90, align: 'center', defaultHidden: true", 'POS Sales Currency must be available from the picker but start hidden.');
    $contains($posSales, "column.key === 'currency_code'", 'POS Sales must render document currency consistently with Sales.');
    $contains($posSales, "is_pos: 1", 'POS Sales must remain permanently POS-only; E3 must not merge it into the normal Sales list.');
}

if ($compiledSales !== false) {
    $contains($compiledSales, 'dataIndex:"currency_code",key:"currency_code",width:90,align:"center",defaultHidden:!0', 'Deployment Sales chunk must default-hide Currency.');
}

if ($compiledPosSales !== false) {
    $contains($compiledPosSales, 'dataIndex:"seller_name",key:"seller_name"', 'Deployment POS Sales chunk must include Seller.');
    $contains($compiledPosSales, 'multiCurrencyEnabled?[{title:', 'Deployment POS Sales chunk must gate Currency on the feature flag.');
    $contains($compiledPosSales, 'dataIndex:"currency_code",key:"currency_code",width:90,align:"center",defaultHidden:!0', 'Deployment POS Sales chunk must default-hide Currency.');
    $contains($compiledPosSales, 'key==="currency_code"', 'Deployment POS Sales chunk must render Currency.');
}

if ($serviceWorker !== false) {
    // A resilient minimum-version check rather than an exact string: any
    // later build (whether another scoped SAFE overlay or an ordinary full
    // Vite rebuild) may legitimately bump this further, and this gate
    // shouldn't need editing every time that happens.
    $versionOk = false;
    if (preg_match("/const VERSION = 'stocky-pwa-v(\d+)';/", $serviceWorker, $m)) {
        $versionOk = ((int) $m[1]) >= 11;
    }
    $assert($versionOk, 'PWA cache version must be at v11 or later (bumped for the synchronized Sales/POS Sales chunks, or by a later build).');
}

if ($controller !== false) {
    $contains($controller, "\$item['seller_name']", 'Sales API must still provide seller_name.');
    $contains($controller, "\$item['currency_code']", 'Sales API must still provide currency_code.');
}

if ($failures) {
    fwrite(STDERR, "Build E3 POS Sales parity gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build E3 POS Sales parity gate: PASS (Seller/Currency parity + default-hidden contract protected).\n";
