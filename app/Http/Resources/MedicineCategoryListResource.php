<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicineCategoryListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'tenant_id'=>$this->tenant_id,
            'name'=>$this->name,
            'medicine_category_id'=>$this->medicine_category_id,
            'slug'=>$this->slug,
            'description'=>$this->description,
            'icon'=>$this->icon,
        ];
    }
}
