<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InvoiceItem extends Model
{
    public const TYPES = ['registration', 'consultation', 'lab', 'medicine', 'procedure', 'other'];

    protected $fillable = [
        'invoice_id', 'item_type', 'reference_type', 'reference_id', 'description', 'quantity', 'unit_price',
        'tax_category', 'tax_rate', 'tax_amount', 'line_total', 'covered', 'payer_amount', 'patient_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float', 'unit_price' => 'decimal:2', 'tax_rate' => 'float', 'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2', 'covered' => 'boolean', 'payer_amount' => 'decimal:2', 'patient_amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScopes();
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
