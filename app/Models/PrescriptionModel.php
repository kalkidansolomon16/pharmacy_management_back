<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrescriptionModel extends Model
{
    protected $fillable = [
        'tenant_id',
        'doctor_id',
        'patient_id',
        'reference_code',
        'expires_at',
        'status',
        'diagnosis',
        'is_digital_signature',
        'notes',
    ];
}
