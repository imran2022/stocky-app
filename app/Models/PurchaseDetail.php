<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseDetail extends Model
{
    protected $fillable = [
        'id', 'purchase_id', 'purchase_unit_id', 'quantity', 'product_id', 'total', 'product_variant_id',
        'cost', 'TaxNet', 'discount', 'discount_method', 'tax_method',
        // Which PO line this GRN line fulfils, if any — see the migration's
        // docblock for why this is line-level, not just header-level.
        'purchase_order_detail_id',
    ];

    protected $casts = [
        'total' => 'double',
        'cost' => 'double',
        'TaxNet' => 'double',
        'discount' => 'double',
        'quantity' => 'double',
        'purchase_id' => 'integer',
        'purchase_unit_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'purchase_order_detail_id' => 'integer',
    ];

    public function purchase()
    {
        return $this->belongsTo('App\Models\Purchase');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function batches()
    {
        return $this->hasMany(PurchaseDetailBatch::class, 'purchase_detail_id');
    }

    public function serials()
    {
        return $this->hasMany(ProductSerial::class, 'purchase_detail_id');
    }

    /** The PO line this GRN line fulfils, if any. */
    public function purchaseOrderDetail()
    {
        return $this->belongsTo(PurchaseOrderDetail::class);
    }
}
