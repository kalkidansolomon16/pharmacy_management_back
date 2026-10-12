<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    protected $fillable = ['stock_transfer_id', 'pharmacy_medicine_id', 'quantity', 'dispatched_quantity', 'allocations'];

    protected function casts(): array
    {
        return ['allocations' => 'array', 'quantity' => 'integer', 'dispatched_quantity' => 'integer'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(PharmacyMedicine::class, 'pharmacy_medicine_id')->withoutGlobalScopes();
    }
}
