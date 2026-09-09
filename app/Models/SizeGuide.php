<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SizeGuide extends Model
{
    use SoftDeletes;

    protected $table = 'size_guides';

    protected $fillable = [
        'name', 'image', 'columns', 'rows', 'status',
    ];

    protected $casts = [
        'columns' => 'array',
        'rows' => 'array',
        'status' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'size_guide_id');
    }
}
