<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientModel extends Model
{
    protected $fillable = [
        'tenant_id',
        'full_name',
        'phone',
        'gender',
        'date_of_birth',
        'address',
        'blood_group',
        'medical_allergies',
    ];
}
