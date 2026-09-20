<?php

/**
 * Build N5 (part 4) — Customer Statement: date-range Opening Balance bug
 * fix + default 90-day range (2026-09-20).
 *
 * Client reported the Customer Statement page getting very long for
 * customers with lots of history and asked whether pagination or a
 * default date range would be better. Investigating the existing
 * from_date/to_date filter (already present on the page) surfaced a real,
 * pre-existing bug: App\Services\ClientStatementService::build() excluded
 * every row dated before `fromDate` from its six source queries, but
 * never folded their net effect into the Opening Balance — so filtering
 * by date silently UNDERSTATED (or overstated) the Opening Balance and
 * every following running Balance for any client with activity before
 * the filter. This made a date-range default (the simpler, safer fix for
 * "page too long" than paginating a running-balance ledger) unsafe to
 * ship until fixed.
 *
 * Real, DB-backed test covering:
 *   1. A client with an invoice + partial payment BEFORE a cutoff date,
 *      and a second invoice AFTER it. Calling build() with
 *      fromDate=cutoff must produce an Opening Balance and closing
 *      Balance that correctly carry forward the pre-cutoff activity —
 *      not just the client's static `opening_balance` column.
 *   2. The no-filter (all-time) call is byte-for-byte unaffected by this
 *      fix (same closing_balance as before, confirming this is additive
 *      and only changes date-filtered calls).
 *   3. Static check: CustomerStatement.vue now defaults to a 90-day range
 *      on first load, with a "Show All Time" reset still available.
 *
 * Run with: php tests/Regression/build_n5_customer_statement_range_fix.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\PaymentSale;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ClientStatementService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$admin = User::first();
Auth::guard('api')->setUser($admin);
Auth::login($admin);

$warehouse = Warehouse::whereNull('deleted_at')->first();
$product = \Illuminate\Support\Facades\DB::table('products')->whereNull('deleted_at')->first();
$assert($warehouse !== null && $product !== null, 'Setup: a warehouse and a product must exist.');

$nextClientCode = ((int) Client::max('code')) + 1;
$client = Client::create([
    'name' => 'N5 Statement Range Client', 'firstname' => 'N5', 'lastname' => 'Range',
    'code' => (string) $nextClientCode,
    'opening_balance' => 100, // client's own static opening balance
    'email' => 'n5range@example.test',
]);

$cutoff = now()->subDays(30)->toDateString();
$beforeCutoff = now()->subDays(60)->toDateString();
$afterCutoff = now()->subDays(10)->toDateString();

// BEFORE the cutoff: a $400 invoice, partially paid $150.
$saleBefore = Sale::create([
    'date' => $beforeCutoff, 'Ref' => 'N5STMTBEFORE'.time(), 'client_id' => $client->id,
    'warehouse_id' => $warehouse->id, 'GrandTotal' => 400, 'paid_amount' => 150,
    'statut' => 'completed', 'payment_statut' => 'partial', 'user_id' => $admin->id,
]);
SaleDetail::create([
    'date' => $beforeCutoff, 'sale_id' => $saleBefore->id, 'product_id' => $product->id,
    'quantity' => 1, 'price' => 400, 'total' => 400, 'TaxNet' => 0, 'discount' => 0,
    'discount_method' => '2', 'tax_method' => '1',
]);
PaymentSale::create([
    'sale_id' => $saleBefore->id, 'date' => $beforeCutoff, 'montant' => 150, 'Ref' => 'N5PMTBEFORE'.time(),
    'user_id' => $admin->id,
]);

// AFTER the cutoff: a $250 invoice, unpaid.
$saleAfter = Sale::create([
    'date' => $afterCutoff, 'Ref' => 'N5STMTAFTER'.time(), 'client_id' => $client->id,
    'warehouse_id' => $warehouse->id, 'GrandTotal' => 250, 'paid_amount' => 0,
    'statut' => 'completed', 'payment_statut' => 'unpaid', 'user_id' => $admin->id,
]);
SaleDetail::create([
    'date' => $afterCutoff, 'sale_id' => $saleAfter->id, 'product_id' => $product->id,
    'quantity' => 1, 'price' => 250, 'total' => 250, 'TaxNet' => 0, 'discount' => 0,
    'discount_method' => '2', 'tax_method' => '1',
]);

// ==================================================================
// All-time call (no filter): baseline, must be unaffected by the fix.
// ==================================================================

echo "== All-time (no date filter): baseline ==\n";

$service = app(ClientStatementService::class);
$allTime = $service->build($client->id, null, null);

// Expected closing balance = client's static opening_balance(100) +
// invoice1(400) - payment1(150) + invoice2(250) = 600.
$expectedAllTimeClosing = 100 + 400 - 150 + 250;
$assert(abs(($allTime['closing_balance'] ?? -99999) - $expectedAllTimeClosing) < 0.01, "All-time closing_balance must be {$expectedAllTimeClosing}. Got: ".($allTime['closing_balance'] ?? 'MISSING'));

// ==================================================================
// Date-filtered call: fromDate = cutoff (after the before-cutoff
// activity, before the after-cutoff invoice). THE BUG FIX under test.
// ==================================================================

echo "== Date-filtered (fromDate=cutoff): Opening Balance must carry forward pre-cutoff activity ==\n";

$filtered = $service->build($client->id, $cutoff, null);

// Opening Balance as of the cutoff = client's static opening_balance(100)
// + before-cutoff invoice(400) - before-cutoff payment(150) = 350.
// BEFORE this fix, this would have been just 100 (the static column),
// silently dropping the 250 net effect of the before-cutoff invoice/payment.
$expectedOpeningAtCutoff = 100 + 400 - 150;
$assert(abs(($filtered['opening_balance'] ?? -99999) - $expectedOpeningAtCutoff) < 0.01, "Date-filtered opening_balance must carry forward pre-cutoff activity and equal {$expectedOpeningAtCutoff} (not just the static 100). Got: ".($filtered['opening_balance'] ?? 'MISSING'));

// Only the after-cutoff invoice should appear as a listed entry (plus the
// synthetic "opening" row) — the before-cutoff sale/payment must NOT be
// individually listed, only folded into the opening balance.
$listedTypes = array_column($filtered['entries'] ?? [], 'type');
$assert(in_array('opening', $listedTypes, true), 'Filtered entries must include a synthetic "opening" row carrying the carried-forward balance.');
$invoiceRefs = array_column($filtered['entries'] ?? [], 'ref');
$assert(in_array($saleAfter->Ref, $invoiceRefs, true), 'The after-cutoff invoice must be listed.');
$assert(! in_array($saleBefore->Ref, $invoiceRefs, true), 'The before-cutoff invoice must NOT be individually listed (it is folded into Opening Balance instead).');

// Closing balance must still equal the SAME all-time total (350 opening +
// 250 after-cutoff invoice = 600) — the fix must not change the final
// answer, only make the intermediate Opening Balance correct when a
// range is applied.
$assert(abs(($filtered['closing_balance'] ?? -99999) - $expectedAllTimeClosing) < 0.01, "Date-filtered closing_balance must still equal the true all-time total ({$expectedAllTimeClosing}) — the fix only corrects the Opening Balance shown for the filtered range, not the true final balance. Got: ".($filtered['closing_balance'] ?? 'MISSING'));

// ==================================================================
// Static check: CustomerStatement.vue defaults to a 90-day range.
// ==================================================================

echo "== Static check: CustomerStatement.vue default 90-day range ==\n";

$root = dirname(__DIR__, 2);
$page = file_get_contents($root.'/resources/src/pages/people/CustomerStatement.vue');
$assert(str_contains($page, "dayjs().subtract(89, 'day')"), 'CustomerStatement.vue must default fromDate to ~90 days ago on first load.');
$assert(str_contains($page, "Show_All_Time"), 'CustomerStatement.vue must offer a "Show All Time" reset.');

$translations = file_get_contents($root.'/database/seeders/translations/en.php');
$assert(str_contains($translations, "'Statement_Default_Range_Hint' =>"), 'en.php must define the default-range hint text.');

// ==================================================================
// Cleanup
// ==================================================================

PaymentSale::where('sale_id', $saleBefore->id)->delete();
SaleDetail::where('sale_id', $saleBefore->id)->delete();
SaleDetail::where('sale_id', $saleAfter->id)->delete();
Sale::whereIn('id', [$saleBefore->id, $saleAfter->id])->forceDelete();
Client::where('id', $client->id)->forceDelete();

if ($failures) {
    fwrite(STDERR, "Build N5 Customer Statement range fix FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build N5 Customer Statement range fix: PASS — Opening Balance now correctly carries forward pre-range activity when a date filter is applied; default 90-day range wired up on the frontend.\n";
