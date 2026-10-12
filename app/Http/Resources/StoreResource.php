<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'code' => $this->code, 'type' => $this->type,
            'is_dispensing' => $this->is_dispensing, 'is_default' => $this->is_default,
            'stock_value' => $this->when(isset($this->stock_value), fn () => round((float) $this->stock_value, 2)),
            'units' => $this->when(isset($this->units), fn () => (int) $this->units),
        ];
    }
}
