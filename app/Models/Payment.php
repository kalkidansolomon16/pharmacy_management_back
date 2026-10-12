<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToTenant, LogsActivity;

    protected $fillable = [
        'tenant_id', 'invoice_id', 'claim_id', 'payer_id', 'amount', 'method', 'reference', 'notes',
        'received_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScopes();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function activityLabel(): string
    {
        return 'ETB '.number_format((float) $this->amount, 2).' via '.$this->method;
    }
}
