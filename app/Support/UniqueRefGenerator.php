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
                // Audit Batch 3 (H6): inside a transaction the generator reads from the transaction's own
                // snapshot, so asking it again can return the SAME stale "last Ref" and every retry would
                // collide. Ask it once more; if it still hands back a taken value, step past the collision
                // using locking reads, which always see the latest committed rows.
                $collided = (string) $model->Ref;
                $generateRef = function () use ($generateRef, $model, $collided): string {
                    $next = (string) $generateRef();

                    return $next !== $collided && ! self::exists($model, $next)
                        ? $next
                        : (self::steppingPast($model, $collided))();
                };
            }
        }

        throw $lastException;
    }

    /**
     * Returns a generator that yields the first Ref after $collided that is not yet in the table.
     * Keeps the prefix, separator and zero padding of the colliding Ref ("SL_0099" -> "SL_0100").
     */
    private static function steppingPast(Model $model, string $collided): callable
    {
        return function () use ($model, $collided): string {
            $candidate = $collided;
            for ($i = 0; $i < 1000; $i++) {
                $candidate = self::increment($candidate);
                if (! self::exists($model, $candidate)) {
                    return $candidate;
                }
            }

            return $candidate;
        };
    }

    /** Locking read (sees the latest committed rows, unlike the transaction snapshot). */
    private static function exists(Model $model, string $ref): bool
    {
        return $model->newQuery()->getQuery()->newQuery()
            ->from($model->getTable())
            ->where('Ref', $ref)
            ->lockForUpdate()
            ->exists();
    }

    /** "SL_0099" -> "SL_0100"; a Ref with no trailing number gets "_1" appended. */
    public static function increment(string $ref): string
    {
        if (preg_match('/^(.*?)(\d+)$/', $ref, $m)) {
            return $m[1].str_pad((string) ((int) $m[2] + 1), strlen($m[2]), '0', STR_PAD_LEFT);
        }

        return $ref.'_1';
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
