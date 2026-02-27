<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicineModel extends Model
{
    protected $fillable = [
        'category_id',
        'generic_name',
        'brand_name',
        'bar_code',
        'dosage_form',
        'strength',
        'pack_size',
        'is_narcotic',
        'storage_conditions',
        'indications',
        'image',
        'status',
        'prescription_required',
    ];
}
