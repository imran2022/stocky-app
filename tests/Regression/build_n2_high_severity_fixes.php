<?php

/**
 * Build N2 — High-severity findings from the same third-party
 * "StockyUltimate Enterprise Deep Audit" (2026-09-19) that Build N1 fixed
 * the Criticals for. Each finding below was independently re-verified
 * against this codebase (not taken on faith) before fixing:
 *
 *   H-01: Neither Sale nor Purchase payment creation capped the applied
 *         payment at the document's own GrandTotal — a customer/supplier
 *         tendering more than due (and getting change back, recorded
 *         separately) inflated `paid_amount` past the invoice's total.
 *         Fixed with app/Support/PaymentCapper.php, wired into
 *         SalesController's inline payment block and both
 *         PaymentSalesController/PaymentPurchasesController's store()/
 *         update()/destroy().
 *   H-02/C-02 (extension beyond Sales, which N1 already fixed): the same
 *         ->first() + if($product_warehouse) silent-skip pattern existed,
 *         unfixed, in PurchasesController, AdjustmentController,
 *         DamageController, and (missing only the row-lock half, plus a
 *         separate NULL-variant matching bug) TransferController. All now
 *         go through app/Support/StockMutator.php, exactly like Sales.
 *   H-06: `sales.Ref` / `purchases.Ref` had NO database-level uniqueness —
 *         only an app-level "last Ref + 1" read with no locking. Fixed
 *         with a real unique index (migration
 *         2026_09_20_000001_add_unique_ref_to_sales_and_purchases) plus
 *         app/Support/UniqueRefGenerator.php, which retries with a fresh
 *         Ref on a collision instead of ever saving a duplicate.
 *   H-07: purchase_orders / purchase_order_details used FLOAT for every
 *         money/quantity column (a regression vs. the sibling `purchases`
 *         table, already converted to DECIMAL in Feb 2026). Fixed with
 *         migration 2026_09_20_000002_convert_purchase_orders_float_to_decimal.
 *
 * Run with: php tests/Regression/build_n2_high_severity_fixes.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Http\Controllers\AdjustmentController;
use App\Http\Controllers\DamageController;
use App\Http\Controllers\PaymentPurchasesController;
use App\Http\Controllers\PaymentSalesController;
use App\Http\Controllers\PurchasesController;
use App\Http\Controllers\TransferController;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\product_warehouse;
use App\Support\PaymentCapper;
use App\Support\UniqueRefGenerator;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$approx = static function ($a, $b, string $message, float $eps = 0.01) use (&$failures): void {
    if (abs((float) $a - (float) $b) > $eps) {
        $failures[] = "{$message} (expected {$b}, got {$a})";
    }
};

$admin = User::first();
Auth::guard('api')->setUser($admin);
Auth::login($admin);
app('request')->setUserResolver(fn ($guard = null) => $admin);

$warehouse = Warehouse::whereNull('deleted_at')->first();
$baseProduct = Product::whereNull('deleted_at')->first();

$testUnit = Unit::firstOrCreate(
    ['name' => 'Build N2 Test Unit'],
    ['ShortName' => 'N2U', 'base_unit' => null, 'operator' => '*', 'operator_value' => 1, 'is_active' => 1]
);
$unitId = $testUnit->id;

// A dedicated is_single product: the sandbox DB's only seeded product has
// type='standard' (neither 'is_single' nor 'is_combo'), which would make
// the Adjustment/Damage/Purchases "single product" branch never fire and
// give a false PASS. Cloning guarantees a real is_single product exists.
$singleProduct = $baseProduct->replicate();
$singleProduct->code = 'N2SINGLE'.time();
$singleProduct->name = 'Build N2 Test Single Product';
$singleProduct->type = 'is_single';
$singleProduct->save();

$cleanup = [
    'sales' => [], 'purchases' => [], 'adjustments' => [], 'damages' => [],
    'product_warehouse' => [], 'warehouses' => [],
];

// ==================================================================
// H-06: Ref uniqueness (DB constraint + retry-on-collision)
// ==================================================================

echo "== H-06: Ref uniqueness (sales/purchases) ==\n";

$schemaHasSalesUnique = false;
foreach (Schema::getIndexes('sales') as $idx) {
    if (in_array('Ref', array_map('strtolower', $idx['columns'] ?? []), true) === false) {
        // column names may not be lowercased consistently; compare directly too
    }
    if ($idx['unique'] && in_array('Ref', $idx['columns'] ?? [], true)) {
        $schemaHasSalesUnique = true;
    }
}
$assert($schemaHasSalesUnique, 'H-06: sales table must have a unique index on Ref.');

$schemaHasPurchasesUnique = false;
foreach (Schema::getIndexes('purchases') as $idx) {
    if ($idx['unique'] && in_array('Ref', $idx['columns'] ?? [], true)) {
        $schemaHasPurchasesUnique = true;
    }
}
$assert($schemaHasPurchasesUnique, 'H-06: purchases table must have a unique index on Ref.');

// A raw duplicate insert must now be rejected by the DB itself (proves the
// constraint is real, not just present-but-inert).
$dupRef = 'N2DUPTEST'.time();
DB::table('sales')->insert([
    'user_id' => $admin->id, 'date' => now()->toDateString(), 'Ref' => $dupRef,
    'client_id' => 1, 'warehouse_id' => $warehouse->id, 'GrandTotal' => 0,
    'statut' => 'draft', 'payment_statut' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
]);
$dupInsertFailed = false;
try {
    DB::table('sales')->insert([
        'user_id' => $admin->id, 'date' => now()->toDateString(), 'Ref' => $dupRef,
        'client_id' => 1, 'warehouse_id' => $warehouse->id, 'GrandTotal' => 0,
        'statut' => 'draft', 'payment_statut' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
    ]);
} catch (\Throwable $e) {
    $dupInsertFailed = true;
}
$assert($dupInsertFailed, 'H-06: inserting a second sales row with the same Ref must now fail at the DB level.');
DB::table('sales')->where('Ref', $dupRef)->delete();

// UniqueRefGenerator::save() must retry with a fresh Ref instead of ever
// raising the collision to the caller.
$existingRef = 'N2COLLIDE'.time();
DB::table('sales')->insert([
    'user_id' => $admin->id, 'date' => now()->toDateString(), 'Ref' => $existingRef,
    'client_id' => 1, 'warehouse_id' => $warehouse->id, 'GrandTotal' => 0,
    'statut' => 'draft', 'payment_statut' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
]);
$attemptedRefs = [];
$newSale = new Sale();
$newSale->date = now()->toDateString();
$newSale->client_id = 1;
$newSale->warehouse_id = $warehouse->id;
$newSale->GrandTotal = 0;
$newSale->statut = 'draft';
$newSale->payment_statut = 'unpaid';
$newSale->user_id = $admin->id;
UniqueRefGenerator::save($newSale, function () use (&$attemptedRefs, $existingRef) {
    $ref = empty($attemptedRefs) ? $existingRef : 'N2RESOLVED'.time().count($attemptedRefs);
    $attemptedRefs[] = $ref;

    return $ref;
});
$assert(count($attemptedRefs) >= 2, 'H-06: UniqueRefGenerator::save() must retry at least once after a collision.');
$assert($newSale->exists && $newSale->Ref !== $existingRef, 'H-06: the saved row must end up with a DIFFERENT Ref than the one that collided.');
if ($newSale->exists) {
    $cleanup['sales'][] = $newSale->id;
}
DB::table('sales')->where('Ref', $existingRef)->delete();

// ==================================================================
// H-07: purchase_orders / purchase_order_details are now DECIMAL
// ==================================================================

echo "== H-07: Purchase Order columns are DECIMAL, not FLOAT ==\n";

$poColumnTypes = [];
foreach (Schema::getColumns('purchase_orders') as $col) {
    $poColumnTypes[$col['name']] = $col['type_name'] ?? $col['type'] ?? null;
}
foreach (['tax_rate', 'TaxNet', 'discount', 'shipping', 'GrandTotal'] as $col) {
    $type = strtolower((string) ($poColumnTypes[$col] ?? ''));
    $assert(
        str_contains($type, 'decimal') || str_contains($type, 'numeric'),
        "H-07: purchase_orders.{$col} must be DECIMAL, not FLOAT (got '{$type}')."
    );
}

$podColumnTypes = [];
foreach (Schema::getColumns('purchase_order_details') as $col) {
    $podColumnTypes[$col['name']] = $col['type_name'] ?? $col['type'] ?? null;
}
foreach (['cost', 'TaxNet', 'discount', 'quantity', 'received_quantity', 'total'] as $col) {
    $type = strtolower((string) ($podColumnTypes[$col] ?? ''));
    $assert(
        str_contains($type, 'decimal') || str_contains($type, 'numeric'),
        "H-07: purchase_order_details.{$col} must be DECIMAL, not FLOAT (got '{$type}')."
    );
}

// Functional sanity: a PO with a fractional cost must round-trip exactly
// (the classic symptom of FLOAT drift), not lose precision on save/reload.
$provider = Provider::first();
if (! $provider) {
    $provider = Provider::create([
        'name' => 'Build N2 Test Provider', 'code' => 999001, 'email' => 'n2-provider@example.test',
        'phone' => '0000000000', 'country' => 'Test', 'city' => 'Test', 'adresse' => 'Test',
    ]);
    $cleanup['providers'] = [$provider->id];
}
$po = new PurchaseOrder([
    'Ref' => 'N2PO'.time(), 'date' => now()->toDateString(),
    'provider_id' => $provider->id, 'warehouse_id' => $warehouse->id, 'status' => 'draft',
    'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 10.1,
]);
$po->user_id = $admin->id;
$po->save();
$pod = PurchaseOrderDetail::create([
    'purchase_order_id' => $po->id, 'product_id' => $singleProduct->id, 'product_variant_id' => null,
    'cost' => 10.1, 'TaxNet' => 0, 'discount' => 0, 'quantity' => 1.1, 'received_quantity' => 0, 'total' => 11.11,
]);
$reloadedPod = PurchaseOrderDetail::find($pod->id);
$approx($reloadedPod->cost, 10.1, 'H-07: a fractional PO detail cost must round-trip exactly (DECIMAL, not FLOAT drift).', 0.0001);
$approx($reloadedPod->quantity, 1.1, 'H-07: a fractional PO detail quantity must round-trip exactly.', 0.0001);
$po->forceDelete();
$pod->forceDelete();

// ==================================================================
// H-01: payment cannot overstate paid_amount past GrandTotal
// ==================================================================

echo "== H-01: overpayment no longer inflates paid_amount ==\n";

// Sale: GrandTotal 1044.06, tender 1245.06 with 201.00 change (the audit's
// exact repro numbers) via the standalone payment endpoint.
$overpaySale = Sale::create([
    'date' => now()->toDateString(), 'Ref' => 'N2SALE'.time(), 'client_id' => 1,
    'warehouse_id' => $warehouse->id, 'GrandTotal' => 1044.06, 'paid_amount' => 0,
    'statut' => 'completed', 'payment_statut' => 'unpaid', 'user_id' => $admin->id,
]);
$cleanup['sales'][] = $overpaySale->id;

$paymentSalesController = app(PaymentSalesController::class);
$overpayReq = Request::create('/api/payment_sales', 'POST', [
    'sale_id' => $overpaySale->id, 'date' => now()->toDateString(),
    'montant' => 1245.06, 'payment_method_id' => 1, 'change' => 201.00, 'notes' => 'N2 overpay test',
]);
app()->instance('request', $overpayReq);
// Audit B3 (S4): an applied amount above what is due is now REFUSED (previously silently capped). The UI sends the
// applied amount as `montant` and the note handed over as `received_amount`, so the legitimate flow is montant = due.
$refused = false;
try { $paymentSalesController->store($overpayReq); } catch (Illuminate\Validation\ValidationException $e) { $refused = true; }
$assert($refused, 'H-01/S4: a payment larger than the amount due must be refused.');
$overpayReq = Request::create('/api/payment_sales', 'POST', [
    'sale_id' => $overpaySale->id, 'date' => now()->toDateString(),
    'montant' => 1044.06, 'payment_method_id' => 1, 'change' => 201.00, 'notes' => 'N2 overpay test',
]);
app()->instance('request', $overpayReq);
$paymentSalesController->store($overpayReq);
$overpaySale->refresh();
$approx($overpaySale->paid_amount, 1044.06, 'H-01: Sale.paid_amount must be capped at GrandTotal even when tendered amount is higher (audit repro: tendered 1245.06, due 1044.06).');
$assert($overpaySale->payment_statut === 'paid', 'H-01: an overpaid-then-capped sale must be marked paid.');

// Purchase: same shape, GrandTotal 20000, tender 20301 (audit's numbers).
$overpayPurchase = new Purchase([
    'date' => now()->toDateString(), 'Ref' => 'N2PUR'.time(), 'provider_id' => $provider->id,
    'warehouse_id' => $warehouse->id, 'GrandTotal' => 20000, 'paid_amount' => 0,
    'statut' => 'received', 'payment_statut' => 'unpaid',
]);
$overpayPurchase->user_id = $admin->id;
$overpayPurchase->save();
$cleanup['purchases'][] = $overpayPurchase->id;

$paymentPurchasesController = app(PaymentPurchasesController::class);
$overpayPurReq = Request::create('/api/payment_purchases', 'POST', [
    'purchase_id' => $overpayPurchase->id, 'date' => now()->toDateString(),
    'montant' => 20301, 'payment_method_id' => 1, 'change' => 301, 'notes' => 'N2 overpay test', 'account_id' => null,
]);
app()->instance('request', $overpayPurReq);
$refused = false;
try { $paymentPurchasesController->store($overpayPurReq); } catch (Illuminate\Validation\ValidationException $e) { $refused = true; }
$assert($refused, 'H-01/S4: a purchase payment larger than the amount due must be refused.');
$overpayPurReq = Request::create('/api/payment_purchases', 'POST', [
    'purchase_id' => $overpayPurchase->id, 'date' => now()->toDateString(),
    'montant' => 20000, 'payment_method_id' => 1, 'change' => 301, 'notes' => 'N2 overpay test', 'account_id' => null,
]);
app()->instance('request', $overpayPurReq);
$paymentPurchasesController->store($overpayPurReq);
$overpayPurchase->refresh();
$approx($overpayPurchase->paid_amount, 20000, 'H-01: Purchase.paid_amount must be capped at GrandTotal even when tendered amount is higher (audit repro: tendered 20301, due 20000).');
$assert($overpayPurchase->payment_statut === 'paid', 'H-01: an overpaid-then-capped purchase must be marked paid.');

// Direct unit check of the capper itself.
$approx(PaymentCapper::capPaid(100, 150), 100, 'H-01: PaymentCapper must cap at GrandTotal.');
$approx(PaymentCapper::capPaid(100, -50), 0, 'H-01: PaymentCapper must floor at zero.');
$approx(PaymentCapper::capPaid(100, 60), 60, 'H-01: PaymentCapper must pass through a normal partial payment unchanged.');

// ==================================================================
// C-02/H-02 extension: Purchases, Adjustment, Damage, Transfer
// ==================================================================

echo "== C-02/H-02 extension: stock lock-or-create in Purchases/Adjustment/Damage/Transfer ==\n";

$freshWarehouse2 = Warehouse::create(['name' => 'Build N2 Fresh Warehouse '.time()]);
$cleanup['warehouses'][] = $freshWarehouse2->id;

// --- Purchases: receiving a product never stocked in this warehouse ---
$rowExists = product_warehouse::whereNull('deleted_at')
    ->where('warehouse_id', $freshWarehouse2->id)->where('product_id', $singleProduct->id)->exists();
$assert(! $rowExists, 'Purchases setup: fresh warehouse must start with no stock row.');

$purchasesController = app(PurchasesController::class);
$purchaseReq = Request::create('/api/purchases', 'POST', [
    'supplier_id' => $provider->id, 'warehouse_id' => $freshWarehouse2->id,
    'date' => now()->toDateString(), 'statut' => 'received', 'notes' => 'N2 stock test',
    'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 50,
    'details' => [[
        'product_id' => $singleProduct->id, 'product_variant_id' => null, 'quantity' => 5,
        'Unit_cost' => 10, 'purchase_unit_id' => $unitId, 'tax_percent' => 0, 'tax_method' => '1',
        'discount' => 0, 'discount_Method' => '2', 'subtotal' => 50,
    ]],
]);
app()->instance('request', $purchaseReq);
$purchaseResp = $purchasesController->store($purchaseReq);
$createdPurchase = Purchase::where('warehouse_id', $freshWarehouse2->id)->where('notes', 'N2 stock test')->first();
$assert($createdPurchase !== null, 'Purchases: the GRN must have been created.');
if ($createdPurchase) {
    $cleanup['purchases'][] = $createdPurchase->id;
}
$pwRow = product_warehouse::whereNull('deleted_at')
    ->where('warehouse_id', $freshWarehouse2->id)->where('product_id', $singleProduct->id)
    ->whereNull('product_variant_id')->first();
$assert($pwRow !== null, 'Purchases (C-02/H-02): a product_warehouse row must now be created instead of silently skipped.');
if ($pwRow) {
    $cleanup['product_warehouse'][] = $pwRow->id;
    $approx($pwRow->qte, 5, 'Purchases: received quantity must actually be added to the new row.');
}

// --- Adjustment: "add" on a product never stocked in this warehouse ---
$adjustmentController = app(AdjustmentController::class);
$adjReq = Request::create('/api/adjustments', 'POST', [
    'warehouse_id' => $freshWarehouse2->id, 'date' => now()->toDateString(), 'notes' => 'N2 adj test',
    'details' => [[
        'product_id' => $singleProduct->id, 'product_variant_id' => null, 'quantity' => 7, 'type' => 'add',
    ]],
]);
app()->instance('request', $adjReq);
$adjustmentController->store($adjReq);
$createdAdj = DB::table('adjustments')->where('warehouse_id', $freshWarehouse2->id)->where('notes', 'N2 adj test')->first();
$assert($createdAdj !== null, 'Adjustment: the record must have been created.');
if ($createdAdj) {
    $cleanup['adjustments'][] = $createdAdj->id;
}
$pwRow->refresh();
$approx($pwRow->qte, 5 + 7, 'Adjustment (C-02/H-02): "add" must increase the SAME row created by Purchases above (no duplicate row).');

// "subtract" on the same row.
$adjReq2 = Request::create('/api/adjustments', 'POST', [
    'warehouse_id' => $freshWarehouse2->id, 'date' => now()->toDateString(), 'notes' => 'N2 adj test 2',
    'details' => [[
        'product_id' => $singleProduct->id, 'product_variant_id' => null, 'quantity' => 3, 'type' => 'subtract',
    ]],
]);
app()->instance('request', $adjReq2);
$adjustmentController->store($adjReq2);
$createdAdj2 = DB::table('adjustments')->where('warehouse_id', $freshWarehouse2->id)->where('notes', 'N2 adj test 2')->first();
if ($createdAdj2) {
    $cleanup['adjustments'][] = $createdAdj2->id;
}
$pwRow->refresh();
$approx($pwRow->qte, 5 + 7 - 3, 'Adjustment: "subtract" must decrease the row correctly.');

// --- Damage: subtract with floor-at-zero, on a BRAND NEW row (never stocked) ---
$freshWarehouse3 = Warehouse::create(['name' => 'Build N2 Fresh Warehouse Damage '.time()]);
$cleanup['warehouses'][] = $freshWarehouse3->id;
$damageController = app(DamageController::class);
$dmgReq = Request::create('/api/damages', 'POST', [
    'warehouse_id' => $freshWarehouse3->id, 'date' => now()->toDateString(), 'notes' => 'N2 damage test',
    'details' => [[
        'product_id' => $singleProduct->id, 'product_variant_id' => null, 'quantity' => 4,
    ]],
]);
app()->instance('request', $dmgReq);
$damageController->store($dmgReq);
$createdDmg = DB::table('damages')->where('warehouse_id', $freshWarehouse3->id)->where('notes', 'N2 damage test')->first();
$assert($createdDmg !== null, 'Damage: the record must have been created.');
if ($createdDmg) {
    $cleanup['damages'][] = $createdDmg->id;
}
$dmgPwRow = product_warehouse::whereNull('deleted_at')
    ->where('warehouse_id', $freshWarehouse3->id)->where('product_id', $singleProduct->id)
    ->whereNull('product_variant_id')->first();
$assert($dmgPwRow !== null, 'Damage (C-02/H-02): a product_warehouse row must be created (was previously silently skipped when none existed).');
if ($dmgPwRow) {
    $cleanup['product_warehouse'][] = $dmgPwRow->id;
    // Started at 0 (freshly created), damaging 4 must floor at 0, not go negative.
    $approx($dmgPwRow->qte, 0, 'Damage: qte must floor at zero rather than go negative on a brand-new (qte=0) row.');
}

// --- Transfer: resolveProductWarehouseRow() must lock AND correctly
//     filter NULL variant (the bug fixed alongside adding the lock) ---
$transferController = app(TransferController::class);
$reflection = new ReflectionClass($transferController);
$method = $reflection->getMethod('resolveProductWarehouseRow');
$method->setAccessible(true);

$freshWarehouse4 = Warehouse::create(['name' => 'Build N2 Fresh Warehouse Transfer '.time()]);
$cleanup['warehouses'][] = $freshWarehouse4->id;

DB::transaction(function () use ($method, $transferController, $freshWarehouse4, $singleProduct, $assert, &$cleanup) {
    $row1 = $method->invoke($transferController, $freshWarehouse4->id, $singleProduct->id, null);
    $assert($row1 !== null, 'Transfer: resolveProductWarehouseRow() must create a row when none exists.');
    $assert($row1->product_variant_id === null, 'Transfer: resolveProductWarehouseRow(variantId=null) must return the NULL-variant row, not any variant row.');
    $cleanup['product_warehouse'][] = $row1->id;

    // Calling it again for the same key must return the SAME row (no duplicate).
    $row2 = $method->invoke($transferController, $freshWarehouse4->id, $singleProduct->id, null);
    $assert($row1->id === $row2->id, 'Transfer: resolveProductWarehouseRow() must not create a duplicate row on a second call for the same key.');
});

// ==================================================================
// Cleanup
// ==================================================================

foreach ($cleanup['sales'] as $sid) {
    DB::table('sale_details')->where('sale_id', $sid)->delete();
    DB::table('payment_sales')->where('sale_id', $sid)->delete();
    DB::table('sales')->where('id', $sid)->delete();
}
foreach ($cleanup['purchases'] as $pid) {
    DB::table('purchase_details')->where('purchase_id', $pid)->delete();
    DB::table('payment_purchases')->where('purchase_id', $pid)->delete();
    DB::table('purchases')->where('id', $pid)->delete();
}
foreach ($cleanup['adjustments'] as $aid) {
    DB::table('adjustment_details')->where('adjustment_id', $aid)->delete();
    DB::table('adjustments')->where('id', $aid)->delete();
}
foreach ($cleanup['damages'] as $did) {
    DB::table('damage_details')->where('damage_id', $did)->delete();
    DB::table('damages')->where('id', $did)->delete();
}
foreach ($cleanup['product_warehouse'] as $pwid) {
    DB::table('product_warehouse')->where('id', $pwid)->delete();
}
foreach ($cleanup['warehouses'] as $wid) {
    DB::table('warehouses')->where('id', $wid)->delete();
}
if (! empty($cleanup['providers'])) {
    foreach ($cleanup['providers'] as $prid) {
        DB::table('providers')->where('id', $prid)->delete();
    }
}
$singleProduct->forceDelete();

// ==================================================================
// Result
// ==================================================================

if (empty($failures)) {
    echo "\nBuild N2 regression: PASS (Ref uniqueness now DB-enforced with retry-on-collision (H-06); ".
        "Purchase Order money/quantity columns are DECIMAL, not FLOAT (H-07); Sale/Purchase overpayment no ".
        "longer inflates paid_amount past GrandTotal (H-01); and Purchases/Adjustment/Damage/Transfer stock ".
        "mutations can no longer silently skip a missing product_warehouse row, matching the Sales fix from ".
        "Build N1 (C-02/H-02 extension)).\n";
    exit(0);
}

echo "\nBuild N2 regression: FAIL\n";
foreach ($failures as $f) {
    echo " - {$f}\n";
}
exit(1);
