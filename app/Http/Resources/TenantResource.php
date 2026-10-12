<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $private = $request->user()?->can('tenants.manage') || $request->user()?->tenant_id === $this->id;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'status' => $this->status,
            'status_reason' => $this->when($private, $this->status_reason),
            'status_changed_at' => $this->when($private, $this->status_changed_at),
            'phone' => $this->phone,
            'email' => $this->email,
            'region' => $this->region,
            'city' => $this->city,
            'sub_city' => $this->sub_city,
            'woreda' => $this->woreda,
            'address_line' => $this->address_line,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'opening_hours' => $this->opening_hours,
            'is_24_hours' => $this->is_24_hours,
            'delivery_available' => $this->delivery_available,
            'description' => $this->description,
            'license_number' => $this->when($private, $this->license_number),
            'tin_number' => $this->when($private, $this->tin_number),
            'approved_at' => $this->approved_at,
            'created_at' => $this->created_at,
            'users_count' => $this->whenCounted('users'),
            'medicines_count' => $this->whenCounted('pharmacyMedicines'),
            'parent_id' => $this->parent_id,
            'modules' => $this->enabledModules(),
            'tax_regime' => $this->when($private, $this->tax_regime),
            'vat_number' => $this->when($private, $this->vat_number),
            'settings' => $this->when($private, fn () => array_merge(\App\Models\Tenant::DEFAULT_SETTINGS, $this->settings ?? [])),
        ];
    }
}
