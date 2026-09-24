<?php
// Audit Batch 5 (S3): stock moves by BASE units for every document type. Box = 12 base units. Deltas are hand-computed
// (not read back from the ledger, which the stress tests already cross-check).
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use App\Http\Controllers\SalesReturnController as SR;
use Illuminate\Support\Facades\DB;

DB::table('units')->insertOrIgnore(['id' => 2, 'name' => 'Box', 'ShortName' => 'bx', 'base_unit' => 1, 'operator' => '*', 'operator_value' => 12, 'is_active' => 1]);
DB::table('settings')->update(['allow_overselling' => 1]);
$s = stk(3, 3);
[$c, $b] = call(CT_P, 'store', pHdr(3, ['GrandTotal' => 240, 'details' => [plm(3, 2, 120, ['purchase_unit_id' => 2])]]));
check('purchase 2 boxes -> +24 base', $c == 200 && abs(stk(3, 3) - ($s + 24)) < 1e-6, "$c ".(stk(3, 3) - $s));
$pid = (int) DB::table('purchases')->max('id');
$s = stk(3, 3);
[$c, $b] = call(CT_P, 'update', pHdr(3, ['GrandTotal' => 360, 'details' => [plm(3, 3, 120, ['purchase_unit_id' => 2, 'id' => DB::table('purchase_details')->where('purchase_id', $pid)->value('id')])]]), 'PUT', [$pid]);
check('edit to 3 boxes -> +12 more', $c == 200 && abs(stk(3, 3) - ($s + 12)) < 1e-6, "$c ".(stk(3, 3) - $s));
$s = stk(3, 3);
[$c, $b, $sid] = mkSale([line(3, 1, 1200, ['sale_unit_id' => 2])], ['warehouse_id' => 3]);
check('sale 1 box -> -12 base', $c == 200 && abs(stk(3, 3) - ($s - 12)) < 1e-6, "$c ".(stk(3, 3) - $s));
$s = stk(3, 3);
[$c, $b] = call(SR::class, 'store', ['client_id' => 2, 'warehouse_id' => 3, 'sale_id' => $sid, 'date' => date('Y-m-d'), 'statut' => 'received', 'notes' => 'u', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 1200,
    'details' => [line(3, 1, 1200, ['sale_unit_id' => 2, 'imei_number' => null])]]);
check('return of that box -> +12 base', $c == 200 && abs(stk(3, 3) - ($s + 12)) < 1e-6, "$c $b ".(stk(3, 3) - $s));
$s = stk(3, 3);
[$c, $b] = destroySale($sid);
check('a sale that already has a return cannot be deleted, and stock stays put', in_array($c, [403, 422]) && abs(stk(3, 3) - $s) < 1e-6, "$c $b");
[$c, $b] = call(CT_P, 'destroy', [], 'DELETE', [$pid]);
check('purchase delete of 3 boxes takes 36 base out again (sale of 12 and return of 12 net to zero)', $c == 200 && abs(stk(3, 3) - ($s - 36)) < 1e-6, "$c $b ".(stk(3, 3) - $s));
finish('Audit B5 unit conversion');
