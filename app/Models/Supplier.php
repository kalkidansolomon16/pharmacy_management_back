<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToTenant, LogsActivity;

    public const TYPES = ['epss', 'manufacturer', 'importer', 'wholesaler', 'other'];

    protected $fillable = [
        'tenant_id', 'name', 'type', 'tin_number', 'license_number', 'phone', 'email',
        'contact_person', 'address', 'payment_terms_days', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'payment_terms_days' => 'integer'];
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function activityLabel(): string
    {
        return $this->name;
    }
}
