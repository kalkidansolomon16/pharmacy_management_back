<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReturn extends Model
{
    use BelongsToTenant;

    public const REASONS = ['near_expiry', 'expired', 'damaged', 'recall', 'wrong_item', 'other'];

    protected $fillable = [
        'tenant_id', 'return_number', 'supplier_id', 'batch_id', 'quantity', 'reason', 'value',
        'status', 'credit_note_number', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'quantity' => 'integer'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MedicineBatch::class, 'batch_id')->withoutGlobalScopes();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
