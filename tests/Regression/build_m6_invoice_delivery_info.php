<?php

/**
 * Build M6 — Delivery Info (Warehouse / Tracking Ref / Zone / Courier) on
 * the Sale Invoice PDF (2026-09-19).
 *
 * Context: the Sale Detail page's header card already shows these four
 * fields (Warehouse, Tracking Ref, Zone, Courier — see SalesController::show(),
 * $sale_details['warehouse']/['tracking_ref']/['zone_name']/['courier_name']),
 * but the invoice PDF never did, on either layout. The user asked to have
 * them on the invoice too, same as the screenshot of the Sale Detail card.
 *
 * This adds a "Delivery Info" block to BOTH the Modern and Classic Sale
 * Invoice templates, gated by a new PdfTemplate toggle
 * (show_delivery_info, default true, editable from Settings -> Invoice PDF
 * -> Sections), and prints only the fields that are actually set on the
 * sale (Warehouse is always set; Tracking Ref/Zone/Courier are optional).
 *
 * This test renders a real sale, with real Warehouse/SaleZone/SaleCourier
 * records, through Sale_PDF_Inline — the same $sale-array-building code
 * (now including warehouse/tracking_ref/zone_name/courier_name) is
 * duplicated identically in Sale_PDF and renderSaleInvoiceHtml (bulk PDF),
 * so exercising one exercises the shared logic all three share — and
 * confirms:
 *   1. Modern layout shows "Delivery Info" with all four fields when all
 *      four are set.
 *   2. Classic layout shows the same (translated) block.
 *   3. When Tracking Ref / Zone / Courier are NOT set on the sale (only
 *      Warehouse, which every sale has), only "Warehouse:" prints — no
 *      empty "Tracking Ref:" / "Zone:" / "Courier:" labels.
 *   4. Turning the show_delivery_info toggle off hides the block entirely,
 *      even though the sale has all four fields set.
 *
 * Run with: php tests/Regression/build_m6_invoice_delivery_info.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\PdfTemplate;
use App\Models\Sale;
use App\Models\SaleCourier;
use App\Models\SaleZone;
use App\Models\User;
use App\Models\Warehouse;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

/**
 * PdfTemplate::settingsFor() caches its result in a `static $cache` local to
 * that method for the lifetime of the PHP process. This test needs to render
 * the same sale under several different PdfTemplate settings (modern,
 * classic, show_delivery_info off) in a row, so each render that depends on
 * a just-changed setting runs in a fresh `php` subprocess instead — exactly
 * like a real second HTTP request would see it (same pattern as Build M3's
 * renderSaleInlineInFreshProcess()).
 */
function renderSaleInlineInFreshProcess(int $saleId): string
{
    $root = dirname(__DIR__, 2);
    $php = <<<PHP
        require '{$root}/vendor/autoload.php';
        \$app = require '{$root}/bootstrap/app.php';
        \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        \$admin = App\Models\User::first();
        Illuminate\Support\Facades\Auth::guard('api')->setUser(\$admin);
        Illuminate\Support\Facades\Auth::login(\$admin);
        app('request')->setUserResolver(fn (\$g = null) => \$admin);
        \$req = Illuminate\Http\Request::create('/api/sales/{$saleId}/pdf-inline', 'GET');
        app()->instance('request', \$req);
        \$controller = app(App\Http\Controllers\SalesController::class);
        echo \$controller->Sale_PDF_Inline(\$req, {$saleId})->getContent();
        PHP;
    $tmpFile = tempnam(sys_get_temp_dir(), 'm6_render_').'.php';
    file_put_contents($tmpFile, "<?php\n".$php);
    $output = shell_exec('php '.escapeshellarg($tmpFile).' 2>&1');
    unlink($tmpFile);

    return $output ?? '';
}

$admin = User::first();
Auth::guard('api')->setUser($admin);
Auth::login($admin);
app('request')->setUserResolver(fn ($guard = null) => $admin);

$warehouse = Warehouse::whereNull('deleted_at')->first();
$product = \Illuminate\Support\Facades\DB::table('products')->whereNull('deleted_at')->first();

$zone = SaleZone::create(['name' => 'Build M6 Test Zone']);
$courier = SaleCourier::create(['name' => 'Build M6 Test Courier']);

$nextClientCode = ((int) Client::max('code')) + 1;
$client = Client::create([
    'name' => 'Build M6 Test Client', 'firstname' => 'M6', 'lastname' => 'Client',
    'code' => (string) $nextClientCode,
]);

$salesController = app(\App\Http\Controllers\SalesController::class);

$makeSale = static function (array $extra) use ($salesController, $client, $warehouse, $product) {
    $req = Request::create('/api/sales', 'POST', array_merge([
        'client_id' => $client->id, 'warehouse_id' => $warehouse->id,
        'date' => now()->toDateString(), 'statut' => 'completed', 'notes' => 'Build M6 delivery info test',
        'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'discount_Method' => '2',
        'shipping' => 0, 'GrandTotal' => 100, 'discount_from_points' => 0, 'used_points' => 0,
        'payment' => ['status' => 'pending'],
        'details' => [[
            'product_id' => $product->id, 'quantity' => 1, 'Unit_price' => 100,
            'tax_percent' => 0, 'tax_method' => '1', 'subtotal' => 100,
            'discount' => 0, 'discount_Method' => '2', 'product_variant_id' => null,
            'serial_numbers' => [], 'sale_unit_id' => $product->unit_sale_id ?? $product->unit_id,
        ]],
    ], $extra));
    app()->instance('request', $req);
    $id = json_decode($salesController->store($req)->getContent(), true)['sale_id'] ?? null;

    return [$id, $req];
};

// ==================== 1 & 2. Modern + Classic, all four fields set ====================
[$saleIdFull] = $makeSale([
    'tracking_ref' => 'DC01', 'zone_id' => $zone->id, 'courier_id' => $courier->id,
]);
$assert($saleIdFull !== null, 'Setup: the full-delivery-info test sale must have been created.');

if ($saleIdFull) {
    PdfTemplate::query()->where('doc_type', 'sale')->delete();
    PdfTemplate::updateOrCreate(['doc_type' => 'sale'], ['settings' => array_merge(PdfTemplate::DEFAULTS, ['layout' => 'modern'])]);
    $modernHtml = renderSaleInlineInFreshProcess($saleIdFull);

    $assert(str_contains($modernHtml, 'Delivery Info'), 'Test 1a: Modern invoice must show the "Delivery Info" block.');
    $assert(str_contains($modernHtml, 'Warehouse:</strong> '.$warehouse->name), 'Test 1b: Modern invoice Delivery Info must show the Warehouse.');
    $assert(str_contains($modernHtml, 'Tracking Ref:</strong> DC01'), 'Test 1c: Modern invoice Delivery Info must show the Tracking Ref.');
    $assert(str_contains($modernHtml, 'Zone:</strong> Build M6 Test Zone'), 'Test 1d: Modern invoice Delivery Info must show the Zone.');
    $assert(str_contains($modernHtml, 'Courier:</strong> Build M6 Test Courier'), 'Test 1e: Modern invoice Delivery Info must show the Courier.');

    PdfTemplate::query()->where('doc_type', 'sale')->delete();
    PdfTemplate::updateOrCreate(['doc_type' => 'sale'], ['settings' => array_merge(PdfTemplate::DEFAULTS, ['layout' => 'classic'])]);
    $classicHtml = renderSaleInlineInFreshProcess($saleIdFull);

    $assert(str_contains($classicHtml, 'Delivery Info'), 'Test 2a: Classic invoice must show the (translated) "Delivery Info" block (rendered visually uppercase via CSS text-transform).');
    $assert(str_contains($classicHtml, '<strong>Warehouse:</strong> '.$warehouse->name), 'Test 2b: Classic invoice Delivery Info must show the Warehouse.');
    $assert(str_contains($classicHtml, '<strong>Tracking Ref:</strong> DC01'), 'Test 2c: Classic invoice Delivery Info must show the Tracking Ref.');
    $assert(str_contains($classicHtml, '<strong>Zone:</strong> Build M6 Test Zone'), 'Test 2d: Classic invoice Delivery Info must show the Zone.');
    $assert(str_contains($classicHtml, '<strong>Courier:</strong> Build M6 Test Courier'), 'Test 2e: Classic invoice Delivery Info must show the Courier.');
}

// ==================== 3. Only Warehouse set (no tracking ref/zone/courier) ====================
[$saleIdBare] = $makeSale([]);
$assert($saleIdBare !== null, 'Setup: the bare (warehouse-only) test sale must have been created.');

if ($saleIdBare) {
    PdfTemplate::query()->where('doc_type', 'sale')->delete();
    PdfTemplate::updateOrCreate(['doc_type' => 'sale'], ['settings' => array_merge(PdfTemplate::DEFAULTS, ['layout' => 'modern'])]);
    $bareHtml = renderSaleInlineInFreshProcess($saleIdBare);

    $assert(str_contains($bareHtml, 'Delivery Info'), 'Test 3a: block must still show (Warehouse is always set).');
    $assert(str_contains($bareHtml, 'Warehouse:</strong> '.$warehouse->name), 'Test 3b: Warehouse must be shown.');
    $assert(! str_contains($bareHtml, 'Tracking Ref:</strong>'), 'Test 3c: "Tracking Ref:" must NOT print when the sale has none.');
    $assert(! str_contains($bareHtml, 'Zone:</strong>'), 'Test 3d: "Zone:" must NOT print when the sale has none.');
    $assert(! str_contains($bareHtml, 'Courier:</strong>'), 'Test 3e: "Courier:" must NOT print when the sale has none.');
}

// ==================== 4. show_delivery_info toggled off ====================
if ($saleIdFull) {
    PdfTemplate::query()->where('doc_type', 'sale')->delete();
    PdfTemplate::updateOrCreate(['doc_type' => 'sale'], ['settings' => array_merge(PdfTemplate::DEFAULTS, ['layout' => 'modern', 'show_delivery_info' => false])]);
    $offHtml = renderSaleInlineInFreshProcess($saleIdFull);

    $assert(! str_contains($offHtml, 'Delivery Info'), 'Test 4: toggling show_delivery_info off must hide the block even though the sale has all four fields set.');
}

// ==================== Cleanup ====================
Sale::where('notes', 'Build M6 delivery info test')->forceDelete();
Client::where('id', $client->id)->forceDelete();
SaleZone::where('id', $zone->id)->forceDelete();
SaleCourier::where('id', $courier->id)->forceDelete();
PdfTemplate::query()->where('doc_type', 'sale')->delete();

// ==================== Report ====================
if ($failures !== []) {
    fwrite(STDERR, "Build M6 regression FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Build M6 regression: PASS (Delivery Info — Warehouse/Tracking Ref/Zone/Courier — prints on both Modern and Classic invoices, only shows fields actually set on the sale, and the show_delivery_info toggle hides it entirely).\n";
