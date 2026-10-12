<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'category' => $this->category,
            'price' => (float) $this->price, 'tax_category' => $this->tax_category, 'is_active' => $this->is_active];
    }
}
