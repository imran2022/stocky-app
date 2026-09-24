<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Audit fix (Batch 2, finding C3): purchases, adjustments, transfers and
 * returns accepted negative quantities (a "purchase of -50" removed stock and
 * returned success), any string as an adjustment type (only the exact value
 * 'add' added stock - 'ADD', 'increase' or '' silently SUBTRACTED), and a
 * transfer from a warehouse to itself. Cheap request-level checks; each throws
 * a ValidationException (HTTP 422) before anything is written.
 */
class StockDocumentRules
{
    public static function assertQuantities(array $lines, string $field = 'details'): void
    {
        foreach ($lines as $i => $l) {
            $q = $l['quantity'] ?? null;
            if (! is_numeric($q) || (float) $q < 0) {
                throw ValidationException::withMessages([
                    "{$field}.{$i}.quantity" => ['Quantity must be a number that is not negative.'],
                ]);
            }
        }
    }

    public static function assertAdjustmentTypes(array $lines): void
    {
        foreach ($lines as $i => $l) {
            if (! in_array($l['type'] ?? null, ['add', 'sub', 'subtract'], true)) {
                throw ValidationException::withMessages([
                    "details.{$i}.type" => ["Adjustment type must be 'add' or 'sub'."],
                ]);
            }
        }
    }

    public static function assertTransferWarehouses($from, $to): void
    {
        if (empty($from) || empty($to) || (int) $from === (int) $to) {
            throw ValidationException::withMessages([
                'transfer.to_warehouse' => ['A transfer needs two different warehouses.'],
            ]);
        }
    }
}
