<?php

namespace App\Services\Costing;

/**
 * Pure Moving Weighted Average replay for ONE product/variant across all of its warehouses.
 *
 * No database, no framework: a deterministic function from an ordered list of stock movements to the costed ledger,
 * so every rule below is unit-testable with plain arrays.
 *
 * Rules (per warehouse, base units):
 *  - Stock IN at a known cost (purchase, adjustment add, seed, transfer-in, sale-return) blends into the average:
 *        avg' = (qty*avg + q*cost) / (qty + q)
 *  - Stock OUT (sale, adjustment remove, damage, transfer-out, negative seed) leaves at the CURRENT average, so the
 *    average does not move and value = qty * avg always.
 *  - Purchase RETURN leaves at the cost printed on the return line (the supplier credit), and the average is
 *    re-derived from what remains: avg' = (qty*avg - q*cost) / (qty - q).
 *  - Sale RETURN comes back at the unit cost of the ORIGINAL sale line (falls back to the current average).
 *  - Transfer-IN arrives at the unit cost the goods had when they left the source warehouse.
 *  - Negative or zero stock: the average is carried unchanged while quantity is <= 0; when goods are received into
 *    a non-positive balance the average resets to the cost of the receipt.
 *
 * Movement shape (associative array, already sorted by the caller in the same order as
 * ProductMovementLedgerService: occurred_at, priority, id):
 *   type        purchase|purchase_return|sale|sale_return|adjustment|damage|transfer_out|transfer_in|seed
 *   source_id   int   (detail row id; seed id for seeds)
 *   warehouse_id int
 *   qty         float signed base units (+ in, - out)
 *   unit_cost   float|null   known cost per base unit for IN / purchase_return; null = "use running average"
 *   estimated   bool         the unit_cost is a master-cost stamp / seed, not a real purchase cost
 *   orig_sources array<int>  sale_return only: sale detail ids the return came from
 *   pair_source_id int|null  transfer_in only: transfer detail id of the matching transfer_out
 */
class MovingAverageEngine
{
    private const EPS = 0.0000001;

    /**
     * @param  array<int,array<string,mixed>>  $movements
     * @return array{rows: array<int,array<string,mixed>>, balances: array<int,array<string,float|bool>>}
     */
    public static function replay(array $movements): array
    {
        $state = [];            // warehouse => [qty, avg, est]
        $lineCost = [];         // "type:source_id" => [unit, est, qtyAbs]
        $rows = [];

        foreach ($movements as $m) {
            $wid = (int) $m['warehouse_id'];
            $st = $state[$wid] ?? ['qty' => 0.0, 'avg' => 0.0, 'est' => 0.0];
            $qty = round((float) $m['qty'], 4);
            $type = (string) $m['type'];
            $id = (int) $m['source_id'];

            if (abs($qty) < 0.00005) {
                continue;
            }

            $unit = 0.0;
            $estimated = false;
            $valueDelta = 0.0;

            if ($qty > 0) {
                // ---- stock IN ------------------------------------------------------------------------------------
                $cost = $m['unit_cost'] ?? null;
                $est = (bool) ($m['estimated'] ?? false);

                if ($type === 'sale_return') {
                    [$origUnit, $origEst] = self::origSaleCost($m['orig_sources'] ?? [], $lineCost);
                    if ($origUnit !== null) {
                        $cost = $origUnit;
                        $est = $origEst;
                    }
                } elseif ($type === 'transfer_in') {
                    $pair = $lineCost['transfer_out:'.($m['pair_source_id'] ?? 0)] ?? null;
                    if ($pair !== null) {
                        $cost = $pair['unit'];
                        $est = $pair['est'];
                    }
                }

                if ($cost === null) {
                    // no usable cost for this receipt: value it at the running average (or flag it when there is none)
                    $cost = $st['avg'];
                    $est = $est || $st['avg'] <= 0 || $st['est'] > 0.005;
                }
                $cost = (float) $cost;

                if ($st['qty'] <= 0) {
                    $newQty = round($st['qty'] + $qty, 4);
                    if ($newQty > 0) {
                        $st['avg'] = $cost;
                        $st['est'] = $est ? 1.0 : 0.0;
                    } elseif ($st['avg'] <= 0) {
                        $st['avg'] = $cost;
                    }
                    $st['qty'] = $newQty;
                } else {
                    $newQty = round($st['qty'] + $qty, 4);
                    $st['avg'] = ($st['qty'] * $st['avg'] + $qty * $cost) / $newQty;
                    $st['est'] = ($st['qty'] * $st['est'] + $qty * ($est ? 1.0 : 0.0)) / $newQty;
                    $st['qty'] = $newQty;
                }

                $unit = $cost;
                $valueDelta = $qty * $cost;
                $estimated = $est;
            } else {
                // ---- stock OUT -----------------------------------------------------------------------------------
                $out = -$qty;
                $explicit = ($type === 'purchase_return' && isset($m['unit_cost']) && (float) $m['unit_cost'] > 0)
                    ? (float) $m['unit_cost'] : null;

                if ($explicit !== null) {
                    $unit = $explicit;
                    $newQty = round($st['qty'] - $out, 4);
                    if ($newQty > 0) {
                        $newAvg = ($st['qty'] * $st['avg'] - $out * $explicit) / $newQty;
                        if ($newAvg >= 0) {
                            $st['avg'] = $newAvg;
                        }
                    }
                    $st['qty'] = $newQty;
                    $estimated = (bool) ($m['estimated'] ?? false);
                } else {
                    $unit = $st['avg'];
                    $estimated = $st['avg'] <= 0 || $st['est'] > 0.005;
                    $st['qty'] = round($st['qty'] - $out, 4);
                }
                $valueDelta = -$out * $unit;
            }

            $state[$wid] = $st;
            $lineCost[$type.':'.$id] = ['unit' => $unit, 'est' => $estimated, 'qty' => abs($qty)];

            $rows[] = [
                'warehouse_id' => $wid,
                'source_type' => $type,
                'source_id' => $id,
                'reference_id' => $m['reference_id'] ?? null,
                'occurred_at' => $m['occurred_at'] ?? null,
                'qty_delta' => $qty,
                'unit_cost' => $unit,
                'value_delta' => $valueDelta,
                'balance_qty' => $st['qty'],
                'balance_value' => $st['qty'] * $st['avg'],
                'avg_cost' => $st['avg'],
                'is_estimated' => $estimated,
            ];
        }

        $balances = [];
        foreach ($state as $wid => $st) {
            $balances[$wid] = [
                'qty' => $st['qty'],
                'avg_cost' => $st['avg'],
                'value' => $st['qty'] * $st['avg'],
                'is_estimated' => $st['est'] > 0.005 || ($st['qty'] > 0 && $st['avg'] <= 0),
            ];
        }

        return ['rows' => $rows, 'balances' => $balances];
    }

    /**
     * Quantity-weighted unit cost of the original sale line(s) a return came from.
     *
     * @return array{0: float|null, 1: bool}
     */
    private static function origSaleCost(array $sources, array $lineCost): array
    {
        $value = 0.0;
        $qty = 0.0;
        $est = false;
        foreach ($sources as $sid) {
            $c = $lineCost['sale:'.(int) $sid] ?? null;
            if ($c === null) {
                continue;
            }
            $value += $c['unit'] * $c['qty'];
            $qty += $c['qty'];
            $est = $est || $c['est'];
        }

        return $qty <= 0 ? [null, false] : [$value / $qty, $est];
    }
}
