<?php

namespace App\Services\Custom;

use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Unit;
use App\Models\UserWarehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Keeps a Purchase Order's `received_quantity` (per line) and overall
 * `status` in sync whenever a GRN (Purchase) is received against it.
 *
 * Deliberately isolated from PurchasesController::store()/update() — those
 * methods are large, heavily customized, and vendor-adjacent (see
 * docs/ARCHITECTURE_AND_CHANGE_CONTROL.md's caution level for Sales/
 * Products/Purchases controllers). This class is called from exactly two
 * places in store() (a validation call, then applyReceipt(...)), so a
 * future vendor update touching that method has the smallest possible
 * chance of colliding with PO-related logic.
 */
class PurchaseOrderReceiptService
{
    /**
     * Validates a submitted purchase_order_id is safe to receive against
     * BEFORE the GRN is created — called from PurchasesController::store()
     * ahead of its DB transaction, so an invalid reference is rejected
     * fast rather than silently ignored after the fact. Checks, in order:
     * exists (and not soft-deleted), warehouse-accessible to the current
     * user (same UserWarehouse scoping used everywhere else in this app),
     * in a receivable status, that the GRN's own warehouse_id matches the
     * PO's (so stock can't be received into a different warehouse than the
     * one the PO was raised for while still crediting that PO's
     * received_quantity), and — Phase 1.4 — that the GRN's supplier matches
     * the PO's supplier.
     *
     * This is a FAST, NON-LOCKING precheck only, meant to reject an obviously
     * bad reference before opening a transaction. Quantity/over-receipt
     * checks are deliberately NOT done here — see
     * lockAndValidateReceiptLines(), which re-checks everything that matters
     * again under row locks inside the caller's transaction, because a
     * value read here could be stale by the time the transaction commits.
     *
     * Returns null when no PO was referenced at all (the ordinary direct-
     * purchase case this feature doesn't touch). Aborts the request with
     * a 422/403 if a PO *was* referenced but fails any check — this method
     * never silently drops a bad reference.
     */
    public function validateReceivablePo($purchaseOrderId, int $grnWarehouseId, ?int $grnSupplierId = null): ?PurchaseOrder
    {
        if (! $purchaseOrderId) {
            return null;
        }

        $po = PurchaseOrder::whereNull('deleted_at')->find($purchaseOrderId);
        if (! $po) {
            abort(422, 'The selected Purchase Order could not be found.');
        }

        $user = Auth::user();
        if (! $user->is_all_warehouses) {
            $allowed = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->toArray();
            if (! in_array($po->warehouse_id, $allowed, true)) {
                abort(403, 'This Purchase Order is outside your assigned warehouses.');
            }
        }

        if (! in_array($po->status, ['ordered', 'partially_received'], true)) {
            abort(422, 'This Purchase Order is not in a receivable state ('.$po->status.').');
        }

        if ((int) $po->warehouse_id !== $grnWarehouseId) {
            abort(422, "This GRN's warehouse must match the Purchase Order's warehouse.");
        }

        // Phase 1.4: the UI already filters the PO picker by the GRN's
        // selected supplier, but nothing previously stopped a crafted or
        // stale request from pairing a GRN with a PO raised for a
        // different supplier. $grnSupplierId is nullable only so existing
        // callers that haven't been updated yet don't hard-break; every
        // real call site passes it.
        if ($grnSupplierId !== null && (int) $po->provider_id !== $grnSupplierId) {
            abort(422, 'This Purchase Order belongs to a different supplier than the one selected on this GRN.');
        }

        return $po;
    }

    /**
     * Authoritative, row-locked over-receipt check — MUST be called from
     * inside the same DB transaction that will go on to persist the GRN
     * and call applyReceipt(), and BEFORE any stock/PurchaseDetail row for
     * this GRN is written. This is what actually prevents two concurrent
     * requests from both reading the same "remaining 60" and both being
     * allowed to receive 60 (a combined 120 against a PO that only had 60
     * left) — validateReceivablePo() above cannot do this because it
     * intentionally runs before the transaction opens.
     *
     * Locks (in this order, to keep lock order consistent with the rest of
     * this class and reduce deadlock risk): the PurchaseOrder row, then the
     * specific PurchaseOrderDetail rows referenced by $requestDetails.
     * Duplicate lines in the same GRN that reference the same
     * purchase_order_detail_id are summed before comparison, so splitting
     * one quantity across two lines can't bypass the check.
     *
     * Aborts with HTTP 422 and a human message (ordered/already
     * received/attempted/remaining, per line) before returning if any
     * referenced line does not belong to this PO, does not match the
     * line's product/variant, or would push received_quantity past
     * quantity. Rejects the entire GRN — never partially applies or
     * silently clamps.
     *
     * @param  array  $requestDetails  The raw `details` array from the
     *     request (same shape applyReceipt() reads: purchase_order_detail_id,
     *     product_id, product_variant_id, quantity, purchase_unit_id).
     */
    public function lockAndValidateReceiptLines(PurchaseOrder $po, array $requestDetails): void
    {
        // Lock the PO row itself so a concurrent edit/cancel of the PO
        // can't interleave with this receipt decision.
        $lockedPo = PurchaseOrder::whereKey($po->id)->lockForUpdate()->first();
        if (! $lockedPo || ! in_array($lockedPo->status, ['ordered', 'partially_received'], true)) {
            abort(422, 'This Purchase Order is no longer in a receivable state.');
        }

        $detailIds = [];
        foreach ($requestDetails as $row) {
            if (! empty($row['purchase_order_detail_id'])) {
                $detailIds[] = (int) $row['purchase_order_detail_id'];
            }
        }
        if ($detailIds === []) {
            return;
        }

        // Lock every referenced PO-detail row up front, in a stable
        // (ascending id) order, so two concurrent GRNs touching an
        // overlapping set of PO lines always acquire locks in the same
        // order instead of potentially deadlocking each other.
        $lockedDetails = PurchaseOrderDetail::where('purchase_order_id', $lockedPo->id)
            ->whereIn('id', array_unique($detailIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Sum requested base-unit quantity per PO-detail id across every
        // line in this GRN that references it, so duplicate/split lines
        // are checked cumulatively rather than one at a time.
        $requestedByDetail = [];
        foreach ($requestDetails as $row) {
            $poDetailId = $row['purchase_order_detail_id'] ?? null;
            if (! $poDetailId) {
                continue;
            }
            $poDetailId = (int) $poDetailId;

            $detail = $lockedDetails->get($poDetailId);
            if (! $detail) {
                abort(422, "One of this GRN's lines references a Purchase Order detail that does not belong to the selected Purchase Order.");
            }

            $rowProductId = (int) ($row['product_id'] ?? 0);
            $rowVariantId = $row['product_variant_id'] ?? null;
            $rowVariantId = $rowVariantId === null ? null : (int) $rowVariantId;
            if ((int) $detail->product_id !== $rowProductId || (int) ($detail->product_variant_id ?? 0) !== (int) ($rowVariantId ?? 0)) {
                abort(422, 'One of this GRN\'s lines does not match the product/variant of the Purchase Order line it references.');
            }

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

            $requestedByDetail[$poDetailId] = ($requestedByDetail[$poDetailId] ?? 0.0) + $baseQty;
        }

        $problems = [];
        foreach ($requestedByDetail as $poDetailId => $attempted) {
            $detail = $lockedDetails->get($poDetailId);
            $remaining = (float) $detail->quantity - (float) $detail->received_quantity;
            if ($attempted - $remaining > 0.0001) {
                $productName = optional($detail->product)->name ?: 'Product #'.$detail->product_id;
                $problems[] = sprintf(
                    '%s: ordered %s, already received %s, attempted %s, remaining %s',
                    $productName,
                    number_format((float) $detail->quantity, 2),
                    number_format((float) $detail->received_quantity, 2),
                    number_format($attempted, 2),
                    number_format(max($remaining, 0.0), 2)
                );
            }
        }

        if ($problems !== []) {
            abort(422, 'Cannot receive this GRN because it exceeds the Purchase Order\'s remaining quantity: '.implode(' | ', $problems));
        }
    }

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

        // Every referenced detail line must actually belong to THIS PO —
        // without this check, a crafted request could pair this GRN's
        // purchase_order_id with a purchase_order_detail_id from a
        // completely different PO, incrementing that unrelated PO's
        // received_quantity instead. Loading the valid ID set once (not
        // per-line) keeps this a single extra query regardless of line count.
        $validDetailIds = PurchaseOrderDetail::where('purchase_order_id', $po->id)->pluck('id')->all();

        foreach (array_values($requestDetails) as $i => $row) {
            $poDetailId = $row['purchase_order_detail_id'] ?? null;
            if (! $poDetailId) {
                continue;
            }
            if (! in_array((int) $poDetailId, $validDetailIds, true)) {
                // Silently skip rather than abort the whole GRN — the rest
                // of the receipt (already saved) is legitimate; only this
                // one line's PO-linkage claim is bogus/stale.
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
