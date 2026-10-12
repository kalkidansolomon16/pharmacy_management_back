<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'city' => $this->city,
            'address' => $this->address,
            'license_number' => $this->license_number,
            'locale' => $this->locale,
            'tenant_id' => $this->tenant_id,
            'tenant' => new TenantResource($this->whenLoaded('tenant')),
            'role' => $this->whenLoaded('roles', fn () => $this->roles->first()?->name),
            'role_label' => $this->whenLoaded('roles', fn () => $this->roles->first() ? ($this->roles->first() instanceof \App\Models\Role ? $this->roles->first()->displayName() : $this->roles->first()->name) : null),
            // Permissions granted to this person individually, on top of their role
            'extra_permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->values()),
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
        ];
    }
}
