<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Safely assign a "next Ref" value and save a model, retrying on a rare
 * concurrent-duplicate collision (Build N2, audit finding H-06).
 *
 * Background: `sales.Ref` / `purchases.Ref` were generated purely at the
 * application level ("read the last Ref, add one") with NO database-level
 * uniqueness constraint. Two near-simultaneous requests could both read the
 * same "last Ref" before either committed and save the same value —
 * duplicate reference numbers on two different sales/purchases.
 *
 * This build adds a real database unique index (see the
 * add_unique_ref_to_sales_table / add_unique_ref_to_purchases_table
 * migrations — mirroring the composite (Ref, deleted_at) index already used
 * for payment_sales), so a duplicate can no longer be silently saved. But a
 * hard unique constraint alone would turn that rare race into a visible
 * HTTP 500 for whichever request loses the race — worse for the user than
 * today's silent duplicate. `save()` below closes that gap: on a unique-key
 * violation it simply generates a fresh Ref and retries the save a few
 * times, so the user seleom notices anything happened.
 */
class UniqueRefGenerator
{
    /**
     * Assign a freshly generated Ref to $model->Ref and save it, retrying
     * with a newly generated Ref if a concurrent request already claimed
     * the one we picked (detected via a DB unique-constraint violation).
     *
     * @param  Model  $model  Unsaved (or being re-saved) model with a Ref column.
     * @param  callable():string  $generateRef  Returns a fresh candidate Ref each call.
     * @param  int  $maxAttempts
     *
     * @throws QueryException if every attempt collides, or the failure is unrelated to Ref uniqueness.
     */
    public static function save(Model $model, callable $generateRef, int $maxAttempts = 5): void
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $model->Ref = $generateRef();

            try {
                $model->save();

                return;
            } catch (QueryException $e) {
                if (! self::isDuplicateRefViolation($e)) {
                    throw $e;
                }

                $lastException = $e;
                // Loop again: regenerate a fresh Ref on the next iteration.
            }
        }

        throw $lastException;
    }

    /**
     * True if this exception is a unique-constraint violation (SQLSTATE
     * 23000 — MySQL error 1062 "Duplicate entry", SQLite "UNIQUE
     * constraint failed"), so it's safe to just retry with a new Ref.
     * Any other DB error is a real problem and must not be swallowed.
     */
    private static function isDuplicateRefViolation(QueryException $e): bool
    {
        if ($e->getCode() !== '23000') {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, '_ref_');
    }
}
