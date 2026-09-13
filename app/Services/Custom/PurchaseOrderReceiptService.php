<?php

namespace App\Services\Custom;

use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Unit;
use Illuminate\Support\Collection;

/**
 * Keeps a Purchase Order's `received_quantity` (per line) and overall
 * `status` in sync whenever a GRN (Purchase) is received against it.
 *
 * Deliberately isolated from PurchasesController::store()/update() — those
 * methods are large, heavily customized, and vendor-adjacent (see
 * docs/ARCHITECTURE_AND_CHANGE_CONTROL.md's caution level for Sales/
 * Products/Purchases controllers). This class is called from exactly one
 * place in each of those two methods (a single `applyReceipt(...)` line),
 * so a future vendor update touching those methods has the smallest
 * possible chance of colliding with PO-related logic.
 */
class PurchaseOrderReceiptService
{
    /**
     * Call after a GRN and its PurchaseDetail rows have already been
     * persisted (i.e. after the existing store()/update() transaction has
     * done everything it already does). No-op unless the GRN both
     * references a PO and was saved with statut 'received' — a GRN saved
     * as pending/ordered hasn't actually received anything yet, exactly
     * mirroring why such a GRN also doesn't move product_warehouse stock.
     *
     * @param  Purchase  $grn  The just-saved GRN, with purchase_order_id set.
     * @param  array  $requestDetails  The raw `details` array from the
     *     request, in original submission order — used only to read each
     *     line's `purchase_order_detail_id` and `purchase_unit_id` (which
     *     don't necessarily survive onto the persisted PurchaseDetail
     *     model in a form this method can rely on across app versions).
     * @param  Collection<int, \App\Models\PurchaseDetail>  $persistedDetails
     *     The freshly-inserted PurchaseDetail rows for this GRN, fetched in
     *     the same "orderBy id asc" order the caller already uses for its
     *     own batch/serial linking — so index i in $requestDetails lines up
     *     with index i here, exactly as the existing batch/serial code
     *     already assumes.
     */
    public function applyReceipt(Purchase $grn, array $requestDetails, Collection $persistedDetails): void
    {
        if (! $grn->purchase_order_id || $grn->statut !== 'received') {
            return;
        }

        $po = PurchaseOrder::find($grn->purchase_order_id);
        if (! $po) {
            return;
        }

        $touchedAnyLine = false;

        foreach (array_values($requestDetails) as $i => $row) {
            $poDetailId = $row['purchase_order_detail_id'] ?? null;
            if (! $poDetailId) {
                continue;
            }

            $persistedDetail = $persistedDetails->get($i);
            if (! $persistedDetail) {
                continue;
            }

            // Record which PO line this GRN line fulfilled — needed for the
            // "PO Qty vs Received Qty" and price-mismatch display on the PO
            // detail page, and so a later edit/void of this GRN line can
            // find its way back to the right PO line.
            $persistedDetail->purchase_order_detail_id = $poDetailId;
            $persistedDetail->save();

            // Base-unit conversion — identical rule to the stock-deduction
            // block directly above this call in PurchasesController, so a
            // PO line's received_quantity is comparable to its quantity
            // (both in base units) regardless of which purchase unit the
            // GRN happened to use.
            $qty = (float) ($row['quantity'] ?? 0);
            $unitId = $row['purchase_unit_id'] ?? null;
            $baseQty = $qty;
            if ($unitId) {
                $unit = Unit::find($unitId);
                if ($unit) {
                    $baseQty = $unit->operator === '/'
                        ? $qty / ($unit->operator_value ?: 1)
                        : $qty * ($unit->operator_value ?: 1);
                }
            }

            PurchaseOrderDetail::where('id', $poDetailId)->increment('received_quantity', $baseQty);
            $touchedAnyLine = true;
        }

        if ($touchedAnyLine) {
            $this->refreshStatus($po);
        }
    }

    /**
     * Recompute and persist a PO's status from its lines' received_quantity
     * vs quantity — a pure function of current quantities, so it's safe to
     * call after either an increase (a GRN received) or a decrease (a GRN
     * deleted/reverted — see revertReceipt()). draft/cancelled are the only
     * sticky, purely-manual states this never overrides: a cancelled PO
     * shouldn't spring back to "ordered" just because a stray GRN was
     * deleted against it.
     */
    public function refreshStatus(PurchaseOrder $po): void
    {
        $po->load('details');
        $lines = $po->details;

        if ($lines->isEmpty() || in_array($po->status, ['draft', 'cancelled'], true)) {
            return;
        }

        $allFullyReceived = $lines->every(fn (PurchaseOrderDetail $d) => (float) $d->received_quantity >= (float) $d->quantity);
        $anyReceived = $lines->contains(fn (PurchaseOrderDetail $d) => (float) $d->received_quantity > 0);

        if ($allFullyReceived) {
            $po->status = 'received';
        } elseif ($anyReceived) {
            $po->status = 'partially_received';
        } else {
            // Every receipt against this PO has been reverted — back to
            // "ordered" rather than leaving it stuck as received/partially.
            $po->status = 'ordered';
        }

        $po->save();
    }

    /**
     * Call when a GRN linked to a PO is being deleted (or, in the future,
     * had a line removed) — decrements each affected PO line's
     * received_quantity back down and recomputes status. Without this, a
     * deleted GRN would leave its PO permanently stuck showing more
     * received than actually happened. Operates on the GRN's own
     * already-persisted PurchaseDetail rows (quantity + purchase_unit_id),
     * not the original request, so it works correctly regardless of how
     * long ago the GRN was created.
     */
    public function revertReceipt(Purchase $grn): void
    {
        if (! $grn->purchase_order_id) {
            return;
        }

        $po = PurchaseOrder::find($grn->purchase_order_id);
        if (! $po) {
            return;
        }

        $details = $grn->details()->whereNotNull('purchase_order_detail_id')->get();
        if ($details->isEmpty()) {
            return;
        }

        foreach ($details as $detail) {
            $baseQty = (float) $detail->quantity;
            if ($detail->purchase_unit_id) {
                $unit = Unit::find($detail->purchase_unit_id);
                if ($unit) {
                    $baseQty = $unit->operator === '/'
                        ? $baseQty / ($unit->operator_value ?: 1)
                        : $baseQty * ($unit->operator_value ?: 1);
                }
            }

            // Never let a decrement push received_quantity below zero —
            // defensive against this being called twice for the same GRN,
            // or against data that's already inconsistent for some other
            // reason.
            $poDetail = PurchaseOrderDetail::find($detail->purchase_order_detail_id);
            if ($poDetail) {
                $poDetail->received_quantity = max(0.0, (float) $poDetail->received_quantity - $baseQty);
                $poDetail->save();
            }
        }

        $this->refreshStatus($po);
    }
}
