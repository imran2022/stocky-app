<?php

/**
 * Build P1 — Supplier Statement date-range integrity (2026-09-24).
 *
 * Real, DB-backed test for App\Services\ProviderStatementService.
 *
 * Scenario (original opening balance 500, one opening payment of 200 on
 * 2026-01-05, so the provider row currently holds opening_balance = 300):
 *   received purchase A  2026-01-10  1000  (payments 250 on 01-12, 150 on 02-01)
 *   received purchase B  2026-02-15   600
 *   pending  purchase C  2026-02-20   999  (must be ignored, payment 100 too)
 *   purchase return R    2026-02-20   200  (refund 50 on 2026-03-01)
 *
 * Verified figures:
 *   - all time: closing 1350 (= 300 + (1600-400) - (200-50))
 *   - FROM 2026-02-01: opening row must be 1050 (the real balance at the start
 *     of the period), the pre-period opening payment must NOT appear as a row,
 *     and the closing balance must still be 1350.
 *   - TO 2026-02-28: refund (03-01) excluded, closing 1300.
 *   - 2026-01-01..2026-01-31: closing 1050, includes the opening payment row.
 *   - An opening payment dated AFTER the "to" date is neither listed nor
 *     deducted.
 *
 * Run with: php tests/Regression/build_p1_supplier_statement_range.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Services\ProviderStatementService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$near = static fn ($a, $b): bool => abs((float) $a - (float) $b) < 0.005;

$userId = (int) DB::table('users')->value('id');
$warehouseId = (int) DB::table('warehouses')->whereNull('deleted_at')->value('id');
$assert($userId > 0 && $warehouseId > 0, 'Setup: needs at least one user and one warehouse.');

$now = now();
$providerId = DB::table('providers')->insertGetId([
    'name' => 'Build P1 Test Supplier', 'code' => 'P1-'.uniqid(), 'opening_balance' => 300,
    'created_at' => $now, 'updated_at' => $now,
]);

$purchaseIds = [];
$paymentRefs = [];
$makePurchase = function (string $ref, string $date, float $total, float $paid, string $status) use ($providerId, $userId, $warehouseId, $now, &$purchaseIds) {
    $id = DB::table('purchases')->insertGetId([
        'user_id' => $userId, 'Ref' => $ref, 'date' => $date, 'provider_id' => $providerId,
        'warehouse_id' => $warehouseId, 'GrandTotal' => $total, 'paid_amount' => $paid, 'statut' => $status,
        'payment_statut' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $purchaseIds[] = $id;

    return $id;
};
$makePayment = function (int $purchaseId, string $ref, string $date, float $amount) use ($userId, $now, &$paymentRefs) {
    DB::table('payment_purchases')->insert([
        'user_id' => $userId, 'date' => $date, 'Ref' => $ref, 'purchase_id' => $purchaseId,
        'montant' => $amount, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $paymentRefs[] = $ref;
};

DB::table('provider_opening_balance_payments')->insert([
    'provider_id' => $providerId, 'user_id' => $userId, 'date' => '2026-01-05', 'Ref' => 'P1-OBP-1',
    'montant' => 200, 'created_at' => $now, 'updated_at' => $now,
]);
$a = $makePurchase('P1-PR-A', '2026-01-10', 1000, 400, 'received');
$makePayment($a, 'P1-PAY-1', '2026-01-12', 250);
$makePayment($a, 'P1-PAY-2', '2026-02-01', 150);
$makePurchase('P1-PR-B', '2026-02-15', 600, 0, 'received');
$c = $makePurchase('P1-PR-C', '2026-02-20', 999, 100, 'pending');
$makePayment($c, 'P1-PAY-3', '2026-02-21', 100);
$returnId = DB::table('purchase_returns')->insertGetId([
    'user_id' => $userId, 'date' => '2026-02-20', 'Ref' => 'P1-RT-1', 'provider_id' => $providerId,
    'warehouse_id' => $warehouseId, 'GrandTotal' => 200, 'paid_amount' => 50, 'payment_statut' => 'partial',
    'statut' => 'completed', 'created_at' => $now, 'updated_at' => $now,
]);
DB::table('payment_purchase_returns')->insert([
    'user_id' => $userId, 'date' => '2026-03-01', 'Ref' => 'P1-RPAY-1', 'purchase_return_id' => $returnId,
    'montant' => 50, 'created_at' => $now, 'updated_at' => $now,
]);

$service = app(ProviderStatementService::class);
$types = fn (array $r): array => array_column($r['entries'], 'type');
$refs = fn (array $r): array => array_column($r['entries'], 'ref');

// 1. All time.
$all = $service->build($providerId);
$assert($near($all['closing_balance'], 1350), 'All-time closing must be 1350, got '.$all['closing_balance']);
$assert($near($all['opening_balance'], 500), 'All-time opening must be the ORIGINAL 500, got '.$all['opening_balance']);
$assert(! in_array('P1-PR-C', $refs($all), true) && ! in_array('P1-PAY-3', $refs($all), true), 'Pending purchase and its payment must not appear.');

// 2. From-date filter: the opening line is the true balance at period start.
$from = $service->build($providerId, '2026-02-01');
$assert($near($from['opening_balance'], 1050), 'FROM 2026-02-01 opening must be 1050 (real balance at period start), got '.$from['opening_balance']);
$assert(! in_array('opening_payment', $types($from), true), 'FROM 2026-02-01: the 2026-01-05 opening payment is before the period and must not be listed.');
$assert($near($from['closing_balance'], 1350), 'FROM 2026-02-01 closing must still be 1350, got '.$from['closing_balance']);
$assert(($from['entries'][0]['type'] ?? null) === 'opening' && $near($from['entries'][0]['balance'], 1050), 'FROM 2026-02-01: first row must be the 1050 opening line.');

// 3. To-date filter.
$to = $service->build($providerId, null, '2026-02-28');
$assert($near($to['closing_balance'], 1300), 'TO 2026-02-28 closing must be 1300 (refund on 03-01 excluded), got '.$to['closing_balance']);
$assert(! in_array('P1-RPAY-1', $refs($to), true), 'TO 2026-02-28: the 03-01 refund must not be listed.');

// 4. A window that contains the opening payment.
$jan = $service->build($providerId, '2026-01-01', '2026-01-31');
$assert($near($jan['closing_balance'], 1050), 'January closing must be 1050, got '.$jan['closing_balance']);
$assert(in_array('opening_payment', $types($jan), true), 'January must list the opening payment.');

// 5. Opening payment AFTER the "to" date is neither listed nor deducted.
DB::table('provider_opening_balance_payments')->insert([
    'provider_id' => $providerId, 'user_id' => $userId, 'date' => '2026-04-01', 'Ref' => 'P1-OBP-2',
    'montant' => 40, 'created_at' => $now, 'updated_at' => $now,
]);
DB::table('providers')->where('id', $providerId)->update(['opening_balance' => 260]);
$late = $service->build($providerId, null, '2026-02-28');
$assert(! in_array('P1-OBP-2', $refs($late), true), 'An opening payment dated after the "to" date must not be listed.');
$assert($near($late['closing_balance'], 1300), 'Closing at 2026-02-28 must be unchanged (1300) by a later opening payment, got '.$late['closing_balance']);
$lateAll = $service->build($providerId);
$assert($near($lateAll['closing_balance'], 1310), 'All-time closing after the extra 40 payment must be 1310, got '.$lateAll['closing_balance']);

// ==================== Cleanup ====================
DB::table('payment_purchase_returns')->where('purchase_return_id', $returnId)->delete();
DB::table('purchase_returns')->where('id', $returnId)->delete();
DB::table('payment_purchases')->whereIn('Ref', $paymentRefs)->delete();
DB::table('purchases')->whereIn('id', $purchaseIds)->delete();
DB::table('provider_opening_balance_payments')->where('provider_id', $providerId)->delete();
DB::table('providers')->where('id', $providerId)->delete();

if ($failures !== []) {
    fwrite(STDERR, "Build P1 regression FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Build P1 regression: PASS (supplier statement: all-time, from-date, to-date and windowed figures reconcile; opening balance at period start is correct; out-of-period opening payments are neither listed nor deducted).\n";
