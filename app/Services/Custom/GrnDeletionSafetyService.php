<?php

namespace App\Services\Custom;

use App\Models\PurchaseDetail;
use App\Models\product_warehouse;
use App\Models\Unit;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

/**
 * Transactional preflight for received-GRN deletion.
 *
 * Contributions are aggregated across every selected GRN and duplicate line
 * before comparison. Matching product_warehouse rows are locked until the
 * caller's deletion transaction commits, closing the check/delete race.
 */
class GrnDeletionSafetyService
{
    /** @param Collection<int, \App\Models\Purchase> $purchases */
    public function assertSafeToDelete(Collection $purchases): void
    {
        $received = $purchases->filter(fn ($purchase) => $purchase->statut === 'received');
        if ($received->isEmpty()) {
            return;
        }

        $purchasesById = $received->keyBy('id');
        $details = PurchaseDetail::with('product.unitPurchase')
            ->whereIn('purchase_id', $received->pluck('id'))
            ->get();

        $units = Unit::whereIn('id', $details->pluck('purchase_unit_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $requirements = [];
        foreach ($details as $detail) {
            $purchase = $purchasesById->get($detail->purchase_id);
            if (! $purchase) {
                continue;
            }

            $unit = $detail->purchase_unit_id
                ? $units->get($detail->purchase_unit_id)
                : optional($detail->product)->unitPurchase;
            $baseQty = $this->toBaseQuantity((float) $detail->quantity, $unit);
            $variantId = $detail->product_variant_id === null ? 'base' : (string) $detail->product_variant_id;
            $key = $purchase->warehouse_id.'|'.$detail->product_id.'|'.$variantId;

            if (! isset($requirements[$key])) {
                $requirements[$key] = [
                    'warehouse_id' => (int) $purchase->warehouse_id,
                    'product_id' => (int) $detail->product_id,
                    'product_variant_id' => $detail->product_variant_id,
                    'product_name' => optional($detail->product)->name ?: 'Product #'.$detail->product_id,
                    'quantity' => 0.0,
                ];
            }
            $requirements[$key]['quantity'] += $baseQty;
        }

        // Stable lock order reduces deadlock risk when two bulk operations
        // touch overlapping products in a different selection order.
        ksort($requirements, SORT_STRING);
        $problems = [];
        foreach ($requirements as $required) {
            $stockQuery = product_warehouse::whereNull('deleted_at')
                ->where('warehouse_id', $required['warehouse_id'])
                ->where('product_id', $required['product_id']);

            if ($required['product_variant_id'] === null) {
                $stockQuery->whereNull('product_variant_id');
            } else {
                $stockQuery->where('product_variant_id', $required['product_variant_id']);
            }

            $stockRow = $stockQuery->lockForUpdate()->first();
            $current = $stockRow ? (float) $stockRow->qte : 0.0;
            $result = $current - $required['quantity'];
            if ($result < -0.0001) {
                $problems[] = sprintf(
                    '%s: current stock %s, GRN reversal %s, resulting stock %s',
                    $required['product_name'],
                    number_format($current, 2),
                    number_format($required['quantity'], 2),
                    number_format($result, 2)
                );
            }
        }

        if ($problems !== []) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Cannot delete the selected GRN(s) because stock would become negative: '.implode(' | ', $problems),
            ], 422));
        }
    }

    private function toBaseQuantity(float $quantity, ?Unit $unit): float
    {
        if (! $unit) {
            return $quantity;
        }

        return $unit->operator === '/'
            ? $quantity / ($unit->operator_value ?: 1)
            : $quantity * ($unit->operator_value ?: 1);
    }
}
