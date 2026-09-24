<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Modern Dashboard style + section layout, per user (user_id set) or organisation default (user_id NULL). */
class DashboardPreference extends Model
{
    protected $table = 'dashboard_preferences';

    protected $fillable = ['user_id', 'style', 'layout', 'allow_user_switch'];
}
