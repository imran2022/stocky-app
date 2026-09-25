<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One of Bangladesh's 64 Districts (custom addition — see BdDivision). `aliases` holds known alternate spellings
 * (e.g. Bogra for Bogura, Jessore for Jashore) so App\Support\BdDistrictMatcher can recognize a Zone/Area name
 * regardless of which spelling was typed. Reference data only.
 */
class BdDistrict extends Model
{
    protected $fillable = ['division_id', 'name', 'aliases'];

    protected $casts = [
        'aliases' => 'array',
    ];

    public function division()
    {
        return $this->belongsTo(BdDivision::class, 'division_id');
    }
}
