<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;

class LabTest extends Model
{
    use BelongsToTenant, LogsActivity;

    public const CATEGORIES = ['hematology', 'chemistry', 'parasitology', 'serology', 'urinalysis', 'microbiology', 'other'];

    protected $fillable = [
        'tenant_id', 'code', 'name', 'category', 'specimen', 'unit', 'result_type', 'options',
        'reference_low', 'reference_high', 'critical_low', 'critical_high', 'reference_text',
        'price', 'turnaround_minutes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'reference_low' => 'float',
            'reference_high' => 'float',
            'critical_low' => 'float',
            'critical_high' => 'float',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function referenceLabel(): ?string
    {
        if ($this->reference_text) {
            return $this->reference_text;
        }
        if ($this->reference_low !== null && $this->reference_high !== null) {
            // (string) (float) drops trailing decimal zeros only: "70.00" -> "70", "0.60" -> "0.6"
            return (string) (float) $this->reference_low.' - '.(string) (float) $this->reference_high.($this->unit ? " {$this->unit}" : '');
        }

        return null;
    }

    public function activityLabel(): string
    {
        return $this->name;
    }
}
