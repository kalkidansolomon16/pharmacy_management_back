<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->data['kind'] ?? class_basename($this->type),
            'level' => $this->data['level'] ?? 'info',
            'title' => $this->data['title'] ?? '',
            'message' => $this->data['message'] ?? '',
            'link' => $this->data['link'] ?? null,
            'meta' => $this->data['meta'] ?? [],
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
