<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrescriptionItemModel extends Model
{
    protected $fillable = [
        'prescription_id',
        'medicine_id',
        'instruction_note',
        'frequency',
        'dosage',
        'duration_days',
        'despenced_quantity',
        'is_refillable',
        'refills_count',
    ];
}
