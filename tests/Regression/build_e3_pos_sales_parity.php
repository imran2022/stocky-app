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
$compiledSales = file_get_contents($root.'/public/js/chunks/Sales.DrRmbclp.js');
$compiledPosSales = file_get_contents($root.'/public/js/chunks/PosSales.Tm8D__HT.js');
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
    $contains($serviceWorker, "const VERSION = 'stocky-pwa-v11';", 'PWA cache version must bump for the synchronized Sales/POS Sales chunks.');
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
