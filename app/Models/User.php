<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles,HasApiTokens,HasFactory,Notifiable;

    protected $fillable = [
         'tenant_id',
         'email',
         'password',
        //  'status',
         'address',
         'phone'

    ];
    public function tenant():BelongsTo{
        return $this->belongsTo(Tenant::class);
    }
}
