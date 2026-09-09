<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StorePopup extends Model
{
    protected $table = 'store_popups';

    public const TYPES = ['announcement', 'subscription', 'sale'];

    public const TRIGGERS = ['immediate', 'delay', 'exit'];

    public const FREQUENCIES = ['once', 'session', 'always'];

    protected $fillable = [
        'title', 'message', 'type', 'image', 'cta_label', 'cta_url',
        'enabled', 'trigger', 'delay_seconds', 'frequency',
        'starts_at', 'ends_at', 'sort_order',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'delay_seconds' => 'integer',
        'sort_order' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /** Enabled popups whose optional date window is currently open. */
    public function scopeRunning(Builder $q): Builder
    {
        $now = now();

        return $q->where('enabled', true)
            ->where(fn ($qq) => $qq->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($qq) => $qq->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('sort_order')
            ->orderByDesc('id');
    }
}
