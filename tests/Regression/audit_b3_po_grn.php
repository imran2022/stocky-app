<?php
// Audit Batch 3 (P6): a GRN against a Purchase Order cannot dodge the "not more than ordered" cap.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use Illuminate\Support\Facades\DB;

const CT_PO = App\Http\Controllers\PurchaseOrderController::class;
[$c, $b] = call(CT_PO, 'store', ['date' => '2026-09-24', 'provider_id' => 1, 'warehouse_id' => 3, 'status' => 'ordered', 'GrandTotal' => 1000,
    'details' => [['product_id' => 4, 'product_variant_id' => null, 'cost' => 100, 'quantity' => 10, 'subtotal' => 1000]]]);
$poId = (int) (json_decode($b, true)['id'] ?? 0);
check('PO created', $c == 200 && $poId, "$c $b");
$poLine = (int) DB::table('purchase_order_details')->where('purchase_order_id', $poId)->value('id');

$grn = fn (array $lines) => call(CT_P, 'store', pHdr(3, ['purchase_order_id' => $poId, 'GrandTotal' => 100, 'details' => $lines]));
$link = fn ($q) => pl(4, $q, 100, ['purchase_order_detail_id' => $poLine]);
$recv = fn () => (float) DB::table('purchase_order_details')->where('id', $poLine)->value('received_quantity');

[$c, $b] = $grn([$link(6), $link(-4)]);
check('negative quantity line rejected', $c == 422, "$c $b");
[$c, $b] = $grn([pl(4, 50, 100)]);
check('unlinked line for a product on the PO rejected', $c == 422, "$c $b");
check('nothing was received by the rejected GRNs', $recv() == 0, $recv());
[$c, $b] = $grn([$link(6)]);
check('valid partial receipt (6 of 10) accepted', $c == 200 && $recv() == 6, "$c $b");
[$c, $b] = $grn([$link(5)]);
check('receiving 5 more (11 of 10) still rejected', $c == 422, "$c $b");
finish('Audit B3 PO/GRN');
