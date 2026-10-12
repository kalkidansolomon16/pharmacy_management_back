<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock location inside an organization: main store, OPD / ward / emergency dispensaries.
 */
class Store extends Model
{
    use BelongsToTenant, LogsActivity;

    public const TYPES = ['main', 'dispensary', 'opd', 'ward', 'emergency', 'other'];

    protected $fillable = ['tenant_id', 'name', 'code', 'type', 'is_dispensing', 'is_default'];

    protected function casts(): array
    {
        return ['is_dispensing' => 'boolean', 'is_default' => 'boolean'];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(MedicineBatch::class);
    }

    /** The store an organization sells / dispenses from when none is chosen; created on demand. */
    public static function defaultFor(int $tenantId): self
    {
        $query = static::withoutGlobalScopes()->where('tenant_id', $tenantId);

        return (clone $query)->where('is_default', true)->first()
            ?? (clone $query)->where('is_dispensing', true)->orderBy('id')->first()
            ?? static::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'name' => 'Main dispensary', 'code' => 'DISP',
                'type' => 'dispensary', 'is_dispensing' => true, 'is_default' => true,
            ]);
    }

    public function activityLabel(): string
    {
        return $this->name;
    }
}
