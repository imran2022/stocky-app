<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleZone extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'division_id'];

    public function sales()
    {
        return $this->hasMany(Sale::class, 'zone_id')->whereNull('sales.deleted_at');
    }

    /** Bangladesh Division this Zone/Area belongs to (nullable — see BdDistrictMatcher). */
    public function division()
    {
        return $this->belongsTo(BdDivision::class, 'division_id');
    }
}
