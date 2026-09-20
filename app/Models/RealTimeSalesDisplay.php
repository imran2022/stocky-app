<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealTimeSalesDisplay extends Model
{
    protected $fillable = [
        'name',
        'token_hash',
        'token_encrypted',
        'warehouse_id',
        'warehouse_ids',
        'refresh_seconds',
        'show_customer_names',
        'created_by',
        'scope_user_id',
        'view_all_records',
        'expires_at',
        'last_seen_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
        'token_encrypted',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'warehouse_ids' => 'array',
        'refresh_seconds' => 'integer',
        'show_customer_names' => 'boolean',
        'created_by' => 'integer',
        'scope_user_id' => 'integer',
        'view_all_records' => 'boolean',
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function isAccessible(): bool
    {
        return ! $this->revoked_at && $this->expires_at && $this->expires_at->isFuture();
    }
}
