<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PharmacyMedicineModel extends Model
{
    protected $fillable = [
        'tenant_id',
        'medicine_id',
        'price',
        'is_public'
    ];
}
