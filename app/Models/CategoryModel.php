<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryModel extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'icon'
    ];
}
