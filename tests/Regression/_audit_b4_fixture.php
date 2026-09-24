<?php
// Shared Batch 4 fixture: four sales + two returns on 2030-01-15 with hand-computed totals (see audit_b4_sales_figures.php).
use Illuminate\Support\Facades\DB;

function b4_fixture(): void
{
    try { DB::table('settings')->update(['allow_overselling' => 1]); } catch (Throwable $e) {}
    $D = '2030-01-15';

    // A: 2 x 100 with 10% exclusive line tax (220, line tax 20) + 5% order tax (11) + shipping 10 -> 241
    [$c, $b] = mkSale([line(1, 2, 100, ['tax_percent' => 10, 'tax_method' => '1', 'subtotal' => 220])],
        ['date' => $D, 'tax_rate' => 5, 'TaxNet' => 11, 'shipping' => 10, 'GrandTotal' => 241]);
    check('sale A created', $c == 200, "$c $b");
    // B: inclusive 10% line (200, legacy tax 20) + plain 100, header 10% discount (30) -> 270
    [$c, $b] = mkSale([line(1, 1, 200, ['tax_percent' => 10, 'tax_method' => '2', 'subtotal' => 200]), line(2, 1, 100, ['subtotal' => 100])],
        ['date' => $D, 'discount' => 10, 'discount_Method' => '1', 'GrandTotal' => 270]);
    check('sale B created', $c == 200, "$c $b");
    // C: 500 with a fixed 50 discount -> 450
    [$c, $b] = mkSale([line(1, 1, 500, ['subtotal' => 500])], ['date' => $D, 'discount' => 50, 'discount_Method' => '2', 'GrandTotal' => 450]);
    check('sale C created', $c == 200, "$c $b");
    // D: pending sale must never count
    [$c, $b] = mkSale([line(1, 1, 999, ['subtotal' => 999])], ['date' => $D, 'statut' => 'pending', 'GrandTotal' => 999]);
    check('pending sale created', $c == 200, "$c $b");

    // a received sale return of 100, and a pending one that must not count
    $ins = function ($statut, $gt) use ($D) {
        $id = DB::table('sale_returns')->insertGetId(['Ref' => 'RT-B4-'.$statut, 'date' => $D, 'client_id' => 2, 'warehouse_id' => 1, 'user_id' => 2, 'statut' => $statut,
            'GrandTotal' => $gt, 'TaxNet' => 0, 'shipping' => 0, 'discount' => 0, 'tax_rate' => 0, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sale_return_details')->insert(['sale_return_id' => $id, 'product_id' => 1, 'quantity' => 1, 'price' => $gt, 'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_method' => '2', 'total' => $gt, 'created_at' => now(), 'updated_at' => now()]);
    };
    $ins('received', 100); $ins('pending', 77);

}
