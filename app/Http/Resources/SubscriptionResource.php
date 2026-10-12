<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'status' => $this->status,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'tenant' => $this->whenLoaded('tenant', fn () => ['id' => $this->tenant->id, 'name' => $this->tenant->name, 'type' => $this->tenant->type, 'city' => $this->tenant->city]),
            'trial_ends_at' => $this->trial_ends_at, 'current_period_start' => $this->current_period_start,
            'current_period_end' => $this->current_period_end, 'ends_at' => $this->endsAt(),
            'days_left' => $this->daysLeft(), 'is_usable' => $this->isUsable(),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->sortByDesc('id')->values()->map(fn ($p) => [
                'amount' => (float) $p->amount, 'method' => $p->method, 'reference' => $p->reference, 'months' => $p->months,
                'period_start' => $p->period_start, 'period_end' => $p->period_end, 'created_at' => $p->created_at,
            ])),
        ];
    }
}
