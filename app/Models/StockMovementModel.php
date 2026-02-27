<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovementModel extends Model
{
    protected $fillable = [
        'user_id',
        'medicine_batch_id',
        'type',
        'quantity',
        'reason',
        'created_by',
    ];
}
