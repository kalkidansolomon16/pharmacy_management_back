<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * A billable service: card / registration fee, consultation, procedure, bed day...
 */
class Service extends Model
{
    use BelongsToTenant, LogsActivity;

    public const CATEGORIES = ['registration', 'consultation', 'procedure', 'bed', 'other'];

    protected $fillable = ['tenant_id', 'code', 'name', 'category', 'price', 'tax_category', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function activityLabel(): string
    {
        return $this->name;
    }
}
