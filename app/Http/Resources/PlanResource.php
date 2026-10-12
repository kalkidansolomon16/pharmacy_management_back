<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'description' => $this->description,
            'price_monthly' => (float) $this->price_monthly, 'trial_days' => (int) ($this->trial_days ?? 0), 'modules' => $this->modules, 'max_users' => $this->max_users,
            'max_branches' => $this->max_branches, 'max_stores' => $this->max_stores, 'is_active' => $this->is_active,
        ];
    }
}
