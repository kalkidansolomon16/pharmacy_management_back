<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItemModel extends Model
{
    protected $fillable = [
        'order_id',
        'medicine_id',
        'quantity',
        'unit_price',
    ];
}
