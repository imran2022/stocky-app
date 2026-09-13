<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'provider_id', 'warehouse_id', 'GrandTotal', 'time',
        'discount', 'shipping', 'statut', 'notes', 'TaxNet', 'tax_rate', 'paid_amount',
        'payment_statut', 'created_at', 'updated_at', 'deleted_at',
        // Multi-Currency snapshot; NULL = base currency, rate 1
        'currency_id', 'exchange_rate',
        // PO linkage — NULL for a GRN created without selecting a PO (the
        // app's original, unchanged direct-purchase flow)
        'purchase_order_id',
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
        'paid_amount' => 'double',
        'currency_id' => 'integer',
        'exchange_rate' => 'float',
        'purchase_order_id' => 'integer',
    ];

    public function currency()
    {
        return $this->belongsTo('App\Models\Currency');
    }

    public function details()
    {
        return $this->hasMany('App\Models\PurchaseDetail');
    }

    public function provider()
    {
        return $this->belongsTo('App\Models\Provider');
    }

    public function facture()
    {
        return $this->hasMany('App\Models\PaymentPurchase');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function documents()
    {
        return $this->hasMany('App\Models\PurchaseDocument', 'purchase_id');
    }

    /** The PO this GRN was received against, if any (NULL for a direct
     * purchase created without selecting a PO). */
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
