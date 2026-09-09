<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorePage extends Model
{
    protected $fillable = ['title', 'slug', 'content', 'seo_title', 'seo_description', 'published'];

    protected $casts = ['published' => 'boolean'];
}
