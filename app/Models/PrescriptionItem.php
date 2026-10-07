<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrescriptionItem extends Model
{
    protected $fillable = [
        'prescription_id', 'medicine_id', 'dosage', 'frequency', 'duration_days',
        'total_quantity', 'dispensed_quantity', 'instructions',
    ];

    protected function casts(): array
    {
        return [
            'total_quantity' => 'integer',
            'dispensed_quantity' => 'integer',
            'duration_days' => 'integer',
        ];
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class)->withoutGlobalScopes();
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function remainingQuantity(): int
    {
        return max(0, $this->total_quantity - $this->dispensed_quantity);
    }
}
