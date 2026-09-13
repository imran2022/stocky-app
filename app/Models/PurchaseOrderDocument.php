<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Mirrors App\Models\PurchaseDocument exactly — see that model. */
class PurchaseOrderDocument extends Model
{
    protected $table = 'purchase_order_documents';

    protected $fillable = [
        'purchase_order_id', 'name', 'path', 'size', 'mime_type',
    ];

    protected $dates = ['deleted_at', 'created_at', 'updated_at'];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
