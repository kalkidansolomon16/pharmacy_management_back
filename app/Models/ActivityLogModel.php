<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLogModel extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'action',
        'entity_type',
    ];
}
