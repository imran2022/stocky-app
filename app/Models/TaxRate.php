<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxRate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'country', 'state', 'rate', 'active',
    ];

    protected $casts = [
        'rate' => 'decimal:3',
        'active' => 'boolean',
    ];

    /**
     * Resolve the applicable active tax rate for a location.
     * A row matching both country and state is preferred over a
     * country-only row; returns null when nothing matches.
     */
    public static function resolveForLocation(?string $country, ?string $state = null): ?self
    {
        if (! $country) {
            return null;
        }

        $rows = static::where('active', true)
            ->whereRaw('LOWER(TRIM(country)) = ?', [mb_strtolower(trim($country))])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        if ($state) {
            $stateNeedle = mb_strtolower(trim($state));
            $match = $rows->first(
                fn ($r) => $r->state && mb_strtolower(trim($r->state)) === $stateNeedle
            );
            if ($match) {
                return $match;
            }
        }

        // Fall back to a country-wide row (no state)
        return $rows->first(fn ($r) => empty($r->state)) ?? null;
    }
}
