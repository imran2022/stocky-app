<?php
// Purchase-side helpers for the audit suite (require after _audit_lib.php).
use Illuminate\Support\Facades\DB;
const CT_P = App\Http\Controllers\PurchasesController::class;
const CT_R = App\Http\Controllers\PurchasesReturnController::class;
const CT_T = App\Http\Controllers\TransferController::class;
const CT_A = App\Http\Controllers\AdjustmentController::class;
const CT_PP = App\Http\Controllers\PaymentPurchasesController::class;
function pl($pid, $q, $c, $extra = []) { return ['id' => 0, 'product_id' => $pid, 'product_variant_id' => null, 'quantity' => $q, 'Unit_cost' => $c, 'purchase_unit_id' => 1, 'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2', 'subtotal' => $q * $c, 'no_unit' => 1] + $extra; }
// like pl() but the overrides really win (pl() uses `+`, so its base keys, id and purchase_unit_id among them, cannot be overridden)
function plm($pid, $q, $c, $extra = []) { return array_merge(pl($pid, $q, $c), $extra); }
function pHdr($wh = 3, $o = []) { return array_merge(['supplier_id' => 1, 'warehouse_id' => $wh, 'date' => '2026-09-24', 'statut' => 'received', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0], $o); }
function mkPurchase($pid, $q, $c, $wh = 3, $o = []) { [$code, $b] = call(CT_P, 'store', pHdr($wh, ['GrandTotal' => $q * $c, 'details' => [pl($pid, $q, $c)]] + $o)); return [$code, $b, (int) DB::table('purchases')->max('id')]; }
function stk($p, $w) { return (float) DB::table('product_warehouse')->where('product_id', $p)->where('warehouse_id', $w)->whereNull('product_variant_id')->whereNull('deleted_at')->value('qte'); }
function adjPayload($wh, $type, $q, $pid = 1) { return ['warehouse_id' => $wh, 'date' => '2026-09-24', 'notes' => '', 'details' => [['id' => 0, 'product_id' => $pid, 'product_variant_id' => null, 'quantity' => $q, 'type' => $type, 'no_unit' => 1]]]; }
function trPayload($from, $to, $pid, $q, $statut = 'completed') { return ['transfer' => ['from_warehouse' => $from, 'to_warehouse' => $to, 'date' => '2026-09-24', 'statut' => $statut, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'notes' => ''], 'GrandTotal' => $q * 100, 'details' => [pl($pid, $q, 100)]]; }
function prPayload($purchaseId, $pid, $q, $wh = 3) { return ['purchase_id' => $purchaseId, 'supplier_id' => 1, 'warehouse_id' => $wh, 'date' => '2026-09-24', 'statut' => 'completed', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => $q * 100, 'details' => [pl($pid, $q, 100) + ['imei_number' => null]]]; }
