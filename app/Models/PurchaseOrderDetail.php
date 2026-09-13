<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single Purchase Order line item. See the migration's docblock for why
 * received_quantity is denormalized and who is allowed to write to it
 * (only PurchaseOrderController::applyReceipt()).
 */
class PurchaseOrderDetail extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'product_variant_id',
        'cost', 'TaxNet', 'tax_method', 'discount', 'discount_method',
        'quantity', 'received_quantity', 'total',
    ];

    protected $casts = [
        'purchase_order_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'cost' => 'double',
        'TaxNet' => 'double',
        'discount' => 'double',
        'quantity' => 'double',
        'received_quantity' => 'double',
        'total' => 'double',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Remaining quantity this line still expects, never negative even if
     * an over-receipt pushed received_quantity past quantity. */
    public function getRemainingQuantityAttribute(): float
    {
        return max(0.0, (float) $this->quantity - (float) $this->received_quantity);
    }

    /** True once received_quantity has reached (or passed) quantity. */
    public function getIsFullyReceivedAttribute(): bool
    {
        return (float) $this->received_quantity >= (float) $this->quantity;
    }
}
