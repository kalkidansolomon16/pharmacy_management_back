<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = ['purchase_order_id', 'pharmacy_medicine_id', 'quantity_ordered', 'quantity_received', 'unit_cost'];

    protected function casts(): array
    {
        return ['quantity_ordered' => 'integer', 'quantity_received' => 'integer', 'unit_cost' => 'decimal:2'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(PharmacyMedicine::class, 'pharmacy_medicine_id')->withoutGlobalScopes();
    }

    public function outstanding(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }
}
