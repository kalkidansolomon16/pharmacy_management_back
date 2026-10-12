<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicineCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_am' => $this->name_am,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'description' => $this->description,
            'medicines_count' => $this->whenCounted('medicines'),
            'review_status' => $this->review_status ?? 'approved',
            'review_note' => $this->when($this->review_status === 'rejected', $this->review_note),
            'is_own' => $request->user()?->tenant_id !== null && $this->created_by_tenant_id === $request->user()?->tenant_id,
            'contributed_by' => $this->when($request->user()?->isSuperAdmin() ?? false, fn () => $this->relationLoaded('contributor') ? $this->contributor?->name : null),
            'can_edit' => $request->user()?->can('update', $this->resource) ?? false,
        ];
    }
}
