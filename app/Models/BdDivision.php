<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One of Bangladesh's 8 administrative Divisions (custom addition, for Zone/Area -> Division linking and future
 * Division-wise reporting). Reference data only, seeded by
 * database/migrations/2026_09_27_000001_create_bd_geography_and_zone_division_link.php.
 */
class BdDivision extends Model
{
    protected $fillable = ['name', 'sort_order'];

    public function districts()
    {
        return $this->hasMany(BdDistrict::class, 'division_id');
    }

    public function zones()
    {
        return $this->hasMany(SaleZone::class, 'division_id');
    }
}
