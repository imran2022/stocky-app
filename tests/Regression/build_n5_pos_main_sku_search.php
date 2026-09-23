<?php

/**
 * Build N5 (part 2) — Fix: POS search by a Variable Product's main SKU
 * returned nothing (2026-09-20).
 *
 * Bug report: in POS, searching by a variable product's own SKU (e.g.
 * "75433081") showed no results — only typing an individual variant's own
 * code worked. Sales > Create Sale's product search already handled this
 * correctly (ProductsController::Products_by_Warehouse already returns a
 * `product_code` field carrying the parent SKU alongside each variant
 * row, and SaleForm.vue's filterProduct() matches against it — see the
 * comment already in that code: "A variant's label carries the VARIANT
 * code, so also match the parent product's code").
 *
 * Root cause: PosController::GetProductsByParametre() (POS's own product
 * list, used by pos/get_products_pos) builds one row per variant but
 * never exposed the parent product's own `code` — only the variant's own
 * code — so no row anywhere carried the main SKU for a Variable Product.
 * PosPage.vue's search() filter also only checked `product.code` /
 * `product.barcode`, not the parent SKU.
 *
 * Fix: PosController::GetProductsByParametre() now also returns
 * `product_code` per row (mirrors Products_by_Warehouse exactly), and
 * PosPage.vue's search() (both the "single exact match auto-adds" path
 * and the fuzzy multi-result list) now also matches against it.
 *
 * Run with: php tests/Regression/build_n5_pos_main_sku_search.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductsController;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$read = static fn (string $relative): string => file_get_contents($root.'/'.$relative);

$admin = User::first();
Auth::guard('api')->setUser($admin);
Auth::login($admin);

$req = static function (string $method, string $uri, array $params = []) use ($admin) {
    $r = Request::create($uri, $method, $params);
    $r->setUserResolver(fn ($g = null) => $admin);
    app()->instance('request', $r);

    return $r;
};

$warehouse = Warehouse::whereNull('deleted_at')->first();
$assert($warehouse !== null, 'Setup: at least one warehouse must exist.');
$allWarehouseIds = Warehouse::whereNull('deleted_at')->pluck('id')->toArray();

echo "== Setup: a Variable Product with a real, distinctive main SKU ==\n";

$mainSku = 'N5MAINSKU'.time();
$productsController = app(ProductsController::class);

$variantsPayload = json_encode([
    ['text' => 'Black', 'code' => $mainSku.'-BLK', 'cost' => 500, 'price' => 900],
    ['text' => 'Blue', 'code' => $mainSku.'-BLU', 'cost' => 500, 'price' => 900],
]);
$variantOpening = [];
foreach ($allWarehouseIds as $wid) {
    $variantOpening[$wid] = [10, 10];
}
$createReq = $req('POST', '/api/products', [
    'type' => 'is_variant', 'is_variant' => 'true', 'name' => 'N5 Safety Sku Test Laptop',
    'code' => $mainSku, 'Type_barcode' => 'CODE128', 'category_id' => 1, 'unit_id' => 1,
    'tax_method' => '1', 'is_active' => 1, 'discount_type' => 'fixed', 'discount_method' => '2',
    'variants' => $variantsPayload,
    'warehouses' => json_encode([]),
    'variant_opening' => json_encode($variantOpening),
]);
$createResp = json_decode($productsController->store($createReq)->getContent(), true);
$assert(($createResp['success'] ?? false) === true, 'Setup: creating the test Variable Product must succeed.');
$productId = $createResp['product_id'] ?? null;

// ==================================================================
// PosController::GetProductsByParametre() must return `product_code`
// (the parent SKU) on every variant row.
// ==================================================================

echo "== GetProductsByParametre(): variant rows carry product_code == main SKU ==\n";

$posController = app(PosController::class);
$listReq = $req('GET', '/api/pos/get_products_pos', [
    'warehouse_id' => $warehouse->id, 'stock' => 1, 'product_service' => 1, 'product_combo' => 1,
]);
$listResp = json_decode($posController->GetProductsByParametre($listReq)->getContent(), true);
$rows = collect($listResp['products'] ?? []);
$ourRows = $rows->where('id', $productId);

$assert($ourRows->count() === 2, "Both variant rows for the test product must appear in the POS product list. Got: {$ourRows->count()}");
foreach ($ourRows as $row) {
    $assert(($row['product_code'] ?? null) === $mainSku, "Every variant row must carry product_code == the main SKU ({$mainSku}). Got: ".json_encode($row['product_code'] ?? 'MISSING'));
    $assert($row['code'] !== $mainSku, 'The row\'s own `code` field must remain the VARIANT code (unchanged), not the main SKU — product_code is additive, not a replacement.');
}

// ==================================================================
// Static source-contract check: PosPage.vue's search() now matches
// product_code, both in the exact-match auto-add path and the fuzzy
// multi-result filter — mirroring SaleForm.vue's existing filterProduct().
// ==================================================================

echo "== Static check: PosPage.vue search() matches product_code ==\n";

$posPage = $read('resources/src/pages/pos/PosPage.vue');
$assert(str_contains($posPage, 'product.product_code === this.search_input'), 'PosPage.vue search() exact-match path must also check product.product_code.');
$assert(str_contains($posPage, "const productCode = String(product.product_code || '')"), 'PosPage.vue search() fuzzy filter must also check product.product_code.');
$assert(str_contains($posPage, 'productCode.includes(term)'), 'PosPage.vue search() fuzzy filter must include productCode in the match.');

// Cleanup.
// product_variation_sets only exists once Build N4 is applied; guard so this
// cleanup does not crash on a codebase without N4.
if (\Illuminate\Support\Facades\Schema::hasTable('product_variation_sets')) {
    DB::table('product_variation_sets')->where('product_id', $productId)->delete();
}
DB::table('product_warehouse')->where('product_id', $productId)->delete();
ProductVariant::where('product_id', $productId)->forceDelete();
Product::where('id', $productId)->forceDelete();

if ($failures) {
    fwrite(STDERR, "Build N5 POS main-SKU search fix FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build N5 POS main-SKU search fix: PASS (backend returns product_code per variant row; POS search() matches it in both the exact and fuzzy paths).\n";
