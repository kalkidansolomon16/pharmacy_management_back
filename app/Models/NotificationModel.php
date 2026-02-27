<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationModel extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'data',
        'read_at',
    ];
}
