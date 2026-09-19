<?php

/**
 * Read-only PO/GRN runtime diagnostic.
 * Run from the Stocky root: php tests/Regression/po_grn_runtime.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$errors = [];
foreach (['purchase_orders', 'purchase_order_details', 'purchase_order_documents'] as $table) {
    if (! Schema::hasTable($table)) {
        $errors[] = "Missing database table: {$table}";
    }
}

if (! Schema::hasColumn('purchases', 'purchase_order_id')) {
    $errors[] = 'Missing purchases.purchase_order_id column';
}

$routeActions = [];
foreach (Route::getRoutes() as $route) {
    if (str_starts_with($route->uri(), 'api/purchase_orders')) {
        $routeActions[] = $route->methods()[0].' '.$route->uri();
    }
}

if (! in_array('GET api/purchase_orders', $routeActions, true)) {
    $errors[] = 'Missing GET api/purchase_orders route';
}

if (Schema::hasTable('permissions') && ! DB::table('permissions')->where('name', 'purchase_orders')->exists()) {
    $errors[] = 'Missing purchase_orders permission row';
}

if ($errors !== []) {
    fwrite(STDERR, "PO+GRN runtime check FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    fwrite(STDERR, "Run: php artisan migrate --force\n");
    exit(1);
}

echo "PO+GRN runtime check: PASS (routes, schema, and permission are present).\n";
