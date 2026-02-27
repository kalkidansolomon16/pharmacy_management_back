<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderModel extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'prescription_id',
        'status',
        'total_amount',
    ];
}
