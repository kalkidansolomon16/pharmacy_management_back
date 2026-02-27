<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicineCategory extends Model
{
    protected $fillable = [
        'tenant_id',
        'medicine_category_id',
        'name',
        'slug',
        'description',
        'icon'
    ];
}
