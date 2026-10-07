<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToTenant, HasFactory;

    public const PAYMENT_METHODS = ['cash', 'telebirr', 'cbe_birr', 'chapa', 'bank_transfer', 'insurance'];

    /** Allowed status transitions (the order lifecycle). */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'completed', 'partially_completed', 'rejected', 'cancelled'],
        'confirmed' => ['ready', 'completed', 'partially_completed', 'cancelled'],
        'ready' => ['completed', 'partially_completed', 'cancelled'],
        'completed' => [],
        'partially_completed' => [],
        'cancelled' => [],
        'rejected' => [],
    ];

    public const OPEN_STATUSES = ['pending', 'confirmed', 'ready'];

    public const FULFILLED_STATUSES = ['completed', 'partially_completed'];

    protected $fillable = [
        'order_number', 'tenant_id', 'user_id', 'created_by', 'prescription_id', 'channel', 'status',
        'customer_name', 'customer_phone', 'fulfillment', 'delivery_address', 'payment_method',
        'payment_status', 'subtotal', 'discount', 'total_amount', 'notes', 'cancel_reason', 'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class)->withoutGlobalScopes();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
