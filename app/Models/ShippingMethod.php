<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShippingMethod extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'price', 'active', 'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function regions(): HasMany
    {
        return $this->hasMany(ShippingMethodRegion::class);
    }

    /**
     * A method is available for a country when it has no region rows
     * (available everywhere) or one of its region rows matches.
     */
    public function availableForCountry(?string $country): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->regions->isEmpty()) {
            return true;
        }

        if (! $country) {
            return false;
        }

        $needle = mb_strtolower(trim($country));

        return $this->regions->contains(
            fn ($r) => mb_strtolower(trim($r->country)) === $needle
        );
    }
}
