<?php

namespace App\Providers;

use App\Services\Custom\ActivityLogger;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * NEW FEATURE - SAFE ADDITION (Build I1/I2 — Activity Log)
 *
 * Registers model-event closures for Sale, Purchase, Product, Adjustment,
 * Transfer, User (create + soft-delete) and Client (create only — see note
 * below), writing a row to `activity_logs` for each. Same "softly dispatch
 * from model lifecycle without touching controllers" approach as
 * AccountingV2ServiceProvider, and the same try/catch-every-closure
 * discipline: a logging failure must never break the actual business
 * action.
 *
 * SCOPE NOTE — Client (Customer) update & delete, and User's regular
 * profile edit, are NOT hooked here. Those specific actions persist via a
 * query-builder bulk update (`Client::whereKey($id)->update([...])` /
 * `User::whereId($id)->update([...])`) — Eloquent does not fire model
 * events for that call form (only for an update()/save() call on an
 * already-loaded instance, which is what Sale, Purchase, Product,
 * Adjustment and Transfer all use, and what User's own create/delete use).
 * Those bulk-update actions are logged instead by small explicit
 * ActivityLogger::log() calls added directly in ClientController and
 * UserController at their bulk-update call sites — see the comments there.
 *
 * SOFT DELETE NOTE — none of the models hooked here ever call Eloquent's
 * own ->delete(): every "delete" in this app is really
 * `$model->update(['deleted_at' => now(), ...])`, which fires 'updated',
 * not 'deleted'. So the *only* correct way to detect a delete for a
 * hooked model is inside the `updated` closure, by checking
 * wasChanged('deleted_at'). A registered `deleted` closure would simply
 * never fire in this codebase — not included here for that reason (the
 * explicit ClientController/UserController call sites check the same way).
 */
class ActivityLogServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(base_path('config/activity_log.php'), 'activity_log');
    }

    public function boot()
    {
        if (! Config::get('activity_log.enabled', true)) {
            return;
        }

        $this->registerModelObservers();
    }

    protected function registerModelObservers(): void
    {
        // Build I3 fix: these all describe themselves using the human
        // invoice/reference number the user actually sees on screen (the
        // `Ref` column, e.g. "SL-104"), not the internal database `id`.
        // Previously Sale/Purchase read a non-existent `->reference`
        // attribute (the real column is `Ref`), which is always null in
        // Eloquent, so every entry silently fell back to the raw id
        // ("Sale #5" instead of "Sale SL-104") — Adjustment/Transfer never
        // even attempted to use `Ref` at all. `?:` (not `??`) is used
        // deliberately so an empty-string Ref also falls back to the id,
        // not just a missing one.
        $this->hookModel(\App\Models\Sale::class, 'Sale', fn ($sale) => 'Sale '.($sale->Ref ?: '#'.$sale->id));
        $this->hookModel(\App\Models\Purchase::class, 'Purchase', fn ($purchase) => 'Purchase '.($purchase->Ref ?: '#'.$purchase->id));
        $this->hookModel(\App\Models\Product::class, 'Product', fn ($product) => 'Product "'.($product->name ?? $product->id).'"');

        // Phase 2: Adjustment and Transfer follow the exact same
        // ->save()/->update() (incl. delete-via-update) pattern as Sale/
        // Purchase/Product — confirmed by reading AdjustmentController and
        // TransferController directly, not assumed.
        $this->hookModel(\App\Models\Adjustment::class, 'Adjustment', fn ($adj) => 'Adjustment '.($adj->Ref ?: '#'.$adj->id));
        $this->hookModel(\App\Models\Transfer::class, 'Transfer', fn ($t) => 'Transfer '.($t->Ref ?: '#'.$t->id));

        // Build I3 — audit trail coverage extension (2026-09-19). Six more
        // document types confirmed, by reading each controller directly, to
        // follow the same instance ->save()/->update() (incl.
        // delete-via-update) pattern as Sale/Purchase/Adjustment/Transfer
        // above, so the shared hookModel() below is safe for all of them:
        //  - SalesReturnController::destroy() → $current_SaleReturn->update(['deleted_at'=>...])
        //  - PurchasesReturnController::destroy() → $current_PurchaseReturn->update([...])
        //  - DamageController::destroy() → $current_damage->update([...])
        //  - QuotationsController::destroy() → $Quotation->update([...])
        //  - PurchaseOrderController::store/update() → $order->save() / $po->update([...]);
        //    destroy() → $po->deleted_at = now(); $po->save();
        // A PO's status transitions driven by GRN receiving (ordered →
        // partially_received → received) also go through $po->update([...])
        // in PurchasesController, so they show up here too as ordinary
        // "Purchase Order ... updated" entries with a status old/new diff —
        // no separate "received" action needed.
        $this->hookModel(\App\Models\SaleReturn::class, 'Sale Return', fn ($sr) => 'Sale Return '.($sr->Ref ?: '#'.$sr->id));
        $this->hookModel(\App\Models\PurchaseReturn::class, 'Purchase Return', fn ($pr) => 'Purchase Return '.($pr->Ref ?: '#'.$pr->id));
        $this->hookModel(\App\Models\Damage::class, 'Damage', fn ($d) => 'Damage '.($d->Ref ?: '#'.$d->id));
        $this->hookModel(\App\Models\Quotation::class, 'Quotation', fn ($q) => 'Quotation '.($q->Ref ?: '#'.$q->id));
        $this->hookModel(\App\Models\PurchaseOrder::class, 'Purchase Order', fn ($po) => 'Purchase Order '.($po->Ref ?: '#'.$po->id));

        // Warehouse: create is an instance ->save() (WarehouseController::store),
        // so hookModel's `created` half applies cleanly. Its update() and
        // destroy()/delete_by_selection() all use a bulk
        // Warehouse::whereId()->update([...]) instead (not observable), so —
        // same reasoning as Client/User — those are logged explicitly,
        // directly in WarehouseController.
        $this->hookModel(\App\Models\Warehouse::class, 'Warehouse', fn ($w) => 'Warehouse "'.($w->name ?? $w->id).'"');

        // The four payment types: PaymentXController::store() uses
        // ModelClass::create([...]) and update() uses an instance
        // ->update([...]) — both observable — but destroy() uses a bulk
        // PaymentX::whereId()->update(['deleted_at'=>...]) (not observable),
        // logged explicitly at each of those 4 call sites instead.
        $this->hookModel(\App\Models\PaymentSale::class, 'Payment (Sale)', fn ($p) => 'Payment '.($p->Ref ?: '#'.$p->id));
        $this->hookModel(\App\Models\PaymentPurchase::class, 'Payment (Purchase)', fn ($p) => 'Payment '.($p->Ref ?: '#'.$p->id));
        $this->hookModel(\App\Models\PaymentSaleReturns::class, 'Payment (Sale Return)', fn ($p) => 'Payment '.($p->Ref ?: '#'.$p->id));
        $this->hookModel(\App\Models\PaymentPurchaseReturns::class, 'Payment (Purchase Return)', fn ($p) => 'Payment '.($p->Ref ?: '#'.$p->id));

        // Shipment: create (store()) and update() both use an instance
        // ->save(), observable via hookModel as usual. destroy() is the ONE
        // exception across this whole provider — it calls the model's real
        // Eloquent ->delete() (Shipment does not use the SoftDeletes trait,
        // just a plain `deleted_at` cast column), which fires Eloquent's
        // `deleted` event, not `updated` — so hookModel's own
        // wasChanged('deleted_at') detection would never see it. Registered
        // as its own explicit listener for that reason.
        $this->hookModel(\App\Models\Shipment::class, 'Shipment', fn ($s) => 'Shipment '.($s->Ref ?: '#'.$s->id));
        if (class_exists(\App\Models\Shipment::class)) {
            \App\Models\Shipment::deleted(function ($shipment) {
                try {
                    ActivityLogger::log(
                        'Shipment',
                        'deleted',
                        'Shipment '.($shipment->Ref ?: '#'.$shipment->id).' deleted',
                        \App\Models\Shipment::class,
                        $shipment->id
                    );
                } catch (\Throwable $e) {
                    Log::warning('[ActivityLog] Shipment deleted log failed: '.$e->getMessage());
                }
            });
        }

        // User: `created` (UserController::store uses new User;->save()) and
        // soft-delete (UserController::destroy uses an instance ->save() —
        // both observable here). Regular profile edits in
        // UserController::update() use a bulk `User::whereId()->update()`
        // instead (not observable) and are logged explicitly there — see
        // that controller's inline comment. Never diff a password field
        // regardless of path — see ActivityLogger's ignored-keys list.
        $this->hookModel(\App\Models\User::class, 'User', fn ($u) => 'User "'.trim("{$u->firstname} {$u->lastname}").'"');

        // Client: created only (see class docblock for why update/delete
        // are handled separately, directly in ClientController).
        if (class_exists(\App\Models\Client::class)) {
            \App\Models\Client::created(function ($client) {
                try {
                    ActivityLogger::log(
                        'Customer',
                        'created',
                        'Customer "'.($client->name ?? $client->id).'" created',
                        \App\Models\Client::class,
                        $client->id,
                        null,
                        ActivityLogger::sanitize($client->getAttributes())
                    );
                } catch (\Throwable $e) {
                    Log::warning('[ActivityLog] Client created log failed: '.$e->getMessage());
                }
            });
        }
    }

    /**
     * Registers created/updated for one model, sharing the same
     * created-vs-updated-vs-soft-deleted logic across Sale, Purchase and
     * Product (they all follow the identical ->save()/->update() +
     * update(['deleted_at'=>...]) pattern — see class docblock).
     */
    private function hookModel(string $modelClass, string $module, \Closure $describe): void
    {
        if (! class_exists($modelClass)) {
            return;
        }

        $modelClass::created(function ($model) use ($module, $describe) {
            try {
                ActivityLogger::log(
                    $module,
                    'created',
                    $describe($model).' created',
                    get_class($model),
                    $model->id,
                    null,
                    ActivityLogger::sanitize($model->getAttributes())
                );
            } catch (\Throwable $e) {
                Log::warning("[ActivityLog] {$module} created log failed: ".$e->getMessage());
            }
        });

        $modelClass::updated(function ($model) use ($module, $describe) {
            try {
                if ($model->wasChanged('deleted_at') && $model->deleted_at !== null) {
                    ActivityLogger::log(
                        $module,
                        'deleted',
                        $describe($model).' deleted',
                        get_class($model),
                        $model->id
                    );

                    return;
                }

                [$old, $new] = ActivityLogger::diff($model);

                if (empty($new)) {
                    return; // e.g. only updated_at changed via a touch()
                }

                ActivityLogger::log(
                    $module,
                    'updated',
                    $describe($model).' updated',
                    get_class($model),
                    $model->id,
                    $old,
                    $new
                );
            } catch (\Throwable $e) {
                Log::warning("[ActivityLog] {$module} updated log failed: ".$e->getMessage());
            }
        });
    }
}
