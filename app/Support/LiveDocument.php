<?php

namespace App\Support;

/**
 * Audit fix (Batch 2, finding C4/S6): Sale, Purchase, Transfer, Adjustment and
 * the return documents are "soft deleted" by setting deleted_at by hand, so
 * Model::find()/findOrFail() still return a deleted row. update/destroy/
 * approve/payment endpoints therefore re-processed deleted documents: an edit
 * re-created the lines and moved stock for an invisible document, a second
 * delete removed loyalty points again, a payment was booked to a deleted sale.
 *
 * Call right after the findOrFail in any endpoint that must only act on a live
 * document. Aborts with 404 (HttpException, so it also works inside
 * DB::transaction closures and rolls the transaction back).
 */
class LiveDocument
{
    public static function assert($model, string $label = 'document'): void
    {
        if ($model && $model->deleted_at !== null) {
            abort(404, "This {$label} was deleted.");
        }
    }
}
