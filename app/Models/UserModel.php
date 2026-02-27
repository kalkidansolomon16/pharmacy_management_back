<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserModel extends Model
{
    protected $fillable = [
         'tenant_id',
         'email',
         'password',
         'status',
         'address',
         'phone'

    ];
}
