<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pre-authorization: the payer must approve its share before an expensive invoice is issued.
 */
class PayerAuthorization extends Model
{
    use BelongsToTenant, LogsActivity;

    protected $table = 'authorizations';

    protected $fillable = [
        'tenant_id', 'invoice_id', 'payer_id', 'amount', 'status', 'reference_number', 'notes',
        'requested_by', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScopes();
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Payer::class)->withoutGlobalScopes();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function activityLabel(): string
    {
        return 'Authorization #'.$this->id;
    }
}
