<?php

/**
 * Build A no-dependency regression gate.
 *
 * Run with: php tests/Regression/build_a_stability.php
 * This deliberately avoids Laravel/bootstrap so it can run before Composer
 * dependencies are installed on a replacement deployment package.
 */

require_once __DIR__.'/../../app/Support/SaleDocumentMath.php';

use App\Support\SaleDocumentMath;

$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$contains = static function (string $haystack, string $needle, string $message) use ($assert): void {
    $assert(str_contains($haystack, $needle), $message);
};

// Pure document arithmetic.
$assert(abs(SaleDocumentMath::convert(100, 1.25) - 125.0) < 0.00001, 'Currency conversion must apply document rate.');
$assert(abs(SaleDocumentMath::discount(10, '1', 1.25) - 10.0) < 0.00001, 'Percent discount must not be currency-converted.');
$assert(abs(SaleDocumentMath::discount(10, '2', 1.25) - 12.5) < 0.00001, 'Fixed discount must be currency-converted.');
$assert(abs(SaleDocumentMath::outstanding(100, 40) - 60.0) < 0.00001, 'Partial-paid COD must equal outstanding balance.');
$assert(abs(SaleDocumentMath::outstanding(100, 100) - 0.0) < 0.00001, 'Paid sale COD must be zero.');
$assert(abs(SaleDocumentMath::outstanding(100, 120) - 0.0) < 0.00001, 'Overpaid sale COD must never be negative.');

$root = dirname(__DIR__, 2);
$sales = file_get_contents($root.'/app/Http/Controllers/SalesController.php');
$products = file_get_contents($root.'/app/Http/Controllers/ProductsController.php');
$shipments = file_get_contents($root.'/app/Http/Controllers/ShipmentController.php');
$label = file_get_contents($root.'/resources/views/pdf/shipping_label.blade.php');

// Integration contracts for the six Build A fixes. These source-level guards
// are intentionally narrow: they detect a future vendor merge silently dropping
// a custom integration point even before a database is available.
$contains($sales, '$docCurrency = helpers::Get_Document_Currency($sale_data);', 'Bulk invoice must resolve the sale document currency.');
$contains($sales, '$symbol = $docCurrency[\'code\'];', 'Bulk invoice must render the document currency code.');
$contains($sales, 'SaleDocumentMath::outstanding($sale_data->GrandTotal, $sale_data->paid_amount)', 'Shipping labels must calculate outstanding COD.');
$contains($label, '{{ $sale[\'cod_amount\'] }}', 'Shipping label template must print cod_amount.');
$assert(! str_contains($label, 'Cash on Delivery: {{ $symbol }}{{ $sale[\'GrandTotal\'] }}'), 'Shipping label must not print full GrandTotal as COD.');

$contains($products, '$sortableFields = [', 'Products endpoint must whitelist SQL sort fields.');
$contains($products, "elseif (\$order === 'total_sold_30d')", 'Sold (30d) must have a safe server-side aggregate sorter.');
$contains($products, "elseif (\$order === 'last_sold_date')", 'Last Sold must have a safe server-side aggregate sorter.');
$contains($products, "sold30BaseQty = \\App\\Support\\UnitQuantityResolver::baseQuantityExpression(", 'Sold (30d) sort must use the same base-unit conversion (G1) as the displayed metric.');
$contains($products, "MAX(sale_details.date)", 'Last Sold sort must use the same sale-detail date metric.');

$contains($shipments, '$sortableFields = [', 'Shipments endpoint must whitelist SQL sort fields.');
foreach (['shipment_ref', 'sale_ref', 'customer_name', 'warehouse_name'] as $field) {
    $contains($shipments, "\$order === '{$field}'", "Shipment sort field {$field} must be explicitly handled.");
}
$contains($shipments, '$shipment->phone_number = $request[\'phone_number\'] ?? null;', 'Shipment creation must persist phone_number.');

foreach (['tracking_ref', 'consignment_id', 'zone_id', 'courier_id'] as $field) {
    $contains($sales, '$request->has(\''.$field.'\')', "Sale update must distinguish omitted {$field} from an explicit clear.");
    $contains($sales, '$current_Sale->'.$field, "Sale update must preserve existing {$field} when omitted.");
}

if ($failures) {
    fwrite(STDERR, "Build A regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build A regression gate: PASS (6 stabilization fixes protected).\n";
