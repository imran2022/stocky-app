<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

use App\Support\ProductDisplayName;

$failures = [];

$assertSame = static function (string $expected, string $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message.' Expected: '.var_export($expected, true).'; got: '.var_export($actual, true);
    }
};

$assertSame(
    'Apple Macbook 2026 - Variant: Grey',
    ProductDisplayName::format('Apple Macbook 2026', 'Grey'),
    'Variant products must use the canonical readable label.'
);
$assertSame(
    'Apple Macbook 2026',
    ProductDisplayName::format('Apple Macbook 2026'),
    'Simple product names must remain unchanged.'
);
$assertSame(
    'T-Shirt - Variant: Blue / XL',
    ProductDisplayName::format('T-Shirt', 'Blue / XL'),
    'Multi-option variant names must remain intact.'
);

$requiredConsumers = [
    'app/Http/Controllers/ProductsController.php',
    'app/Http/Controllers/PosController.php',
    'app/Http/Controllers/SalesController.php',
    'app/Http/Controllers/PurchasesController.php',
    'app/Http/Controllers/PurchaseOrderController.php',
    'app/Http/Controllers/QuotationsController.php',
    'app/Http/Controllers/SalesReturnController.php',
    'app/Http/Controllers/PurchasesReturnController.php',
    'app/Http/Controllers/TransferController.php',
    'app/Http/Controllers/AdjustmentController.php',
    'app/Http/Controllers/DamageController.php',
    'app/Http/Controllers/ReportController.php',
    'app/Http/Controllers/PublicInvoiceController.php',
    'app/Http/Controllers/Api/Portal/PortalInvoicePdfController.php',
    'app/Http/Controllers/Api/Store/MyOrdersApiController.php',
    'app/Http/Controllers/Api/Store/OnlineOrdersApiController.php',
    'app/Http/Controllers/KitchenOrderController.php',
    'app/Services/OnlineOrderInvoiceService.php',
    'app/Services/Xero/SyncService.php',
];

foreach ($requiredConsumers as $relativePath) {
    $source = file_get_contents($root.'/'.$relativePath);
    if ($source === false || ! str_contains($source, 'ProductDisplayName::format(')) {
        $failures[] = $relativePath.' must use ProductDisplayName::format().';
    }
}

$legacyPatterns = [
    '/[\'\"]\[[\'\"]\s*\.\s*\$productsVariants->name/',
    '/[\'\"]\[[\'\"]\s*\.\s*\$variant->name/',
    '/[\'\"]\[[\'\"]\s*\.\s*\$product_warehouse/',
    '/[\'\"]\[[\'\"]\s*\.\s*\$product_variant_data/',
    '/[\'\"]\[[\'\"]\s*\.\s*\(\$variant/',
    '/[\'\"]\[[\'\"]\s*\.\s*\$(?:r|row)->variant_name/',
];

foreach ($requiredConsumers as $relativePath) {
    $source = file_get_contents($root.'/'.$relativePath) ?: '';
    foreach ($legacyPatterns as $pattern) {
        if (preg_match($pattern, $source) === 1) {
            $failures[] = $relativePath.' still contains a legacy [Variant]Product formatter.';
            break;
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Build N1 product/variant display-name regression gate: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - '.$failure."\n");
    }
    exit(1);
}

fwrite(STDOUT, "Build N1 product/variant display-name regression gate: PASS.\n");
