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
        ];
    }
}
