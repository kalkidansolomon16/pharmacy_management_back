<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabOrderItem extends Model
{
    protected $fillable = [
        'lab_order_id', 'lab_test_id', 'price', 'status', 'result_value', 'result_text', 'unit', 'flag',
        'reference', 'remarks', 'resulted_by', 'resulted_at', 'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'result_value' => 'float',
            'resulted_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'lab_test_id')->withoutGlobalScopes();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id')->withoutGlobalScopes();
    }

    public function resulter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resulted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
