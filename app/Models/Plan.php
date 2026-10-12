<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['code', 'name', 'description', 'price_monthly', 'trial_days', 'modules', 'max_users', 'max_branches', 'max_stores', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['modules' => 'array', 'price_monthly' => 'decimal:2', 'trial_days' => 'integer', 'is_active' => 'boolean'];
    }
}
