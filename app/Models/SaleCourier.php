<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleCourier extends Model
{
    use SoftDeletes;

    protected $fillable = ['name'];

    public function sales()
    {
        return $this->hasMany(Sale::class, 'courier_id')->whereNull('sales.deleted_at');
    }
}
