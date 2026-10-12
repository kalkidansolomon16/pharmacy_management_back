<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    protected $fillable = ['subscription_id', 'amount', 'method', 'reference', 'months', 'period_start', 'period_end', 'recorded_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'period_start' => 'datetime', 'period_end' => 'datetime'];
    }
}
