<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Purchase Order — the "we intend to buy this" document. Placing one does
 * not move stock; see the migration's docblock for the full status
 * lifecycle and how it relates to GRN (Purchase) receipts.
 *
 * Mirrors App\Models\Purchase's shape/relations deliberately (see that
 * model) so the rest of the app's currency/pricing helpers, which are
 * written against Purchase's column names, work identically here.
 */
class PurchaseOrder extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'expected_delivery_date', 'Ref', 'provider_id', 'warehouse_id',
        'GrandTotal', 'discount', 'shipping', 'status', 'notes', 'TaxNet', 'tax_rate',
        'sent_at', 'created_at', 'updated_at', 'deleted_at',
        // Multi-Currency snapshot; NULL = base currency, rate 1
        'currency_id', 'exchange_rate',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'provider_id' => 'integer',
        'warehouse_id' => 'integer',
        'GrandTotal' => 'double',
        'discount' => 'double',
        'shipping' => 'double',
        'TaxNet' => 'double',
        'tax_rate' => 'double',
        'currency_id' => 'integer',
        'exchange_rate' => 'float',
        'sent_at' => 'datetime',
    ];

    /** Statuses a user may set directly. The other two (partially_received,
     * received) are computed only — see refreshStatusFromReceipts() on the
     * controller. Kept here as the single source of truth for validation
     * rules and the frontend's status dropdown options.
     */
    public const MANUAL_STATUSES = ['draft', 'ordered', 'cancelled'];

    public const ALL_STATUSES = ['draft', 'ordered', 'partially_received', 'received', 'cancelled'];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function details()
    {
        return $this->hasMany(PurchaseOrderDetail::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function documents()
    {
        return $this->hasMany(PurchaseOrderDocument::class);
    }

    /** GRNs (Purchase/`purchases` rows) received against this PO, if any. */
    public function receipts()
    {
        return $this->hasMany(Purchase::class, 'purchase_order_id');
    }
}
