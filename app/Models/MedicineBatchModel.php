<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicineBatchModel extends Model
{
    protected $fillable = [
        'pharmacy_medicine_id',
        'batch_number',
        'quantity',
        'expiry_date',
        'purchase_price',
    ];
}
