<?php

namespace App\Notifications;

class LowStockAlert extends AppNotification
{
    /**
     * @param  array<int, array{name: string, available: int, reorder_level: int}>  $items
     */
    public function __construct(public array $items)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        $count = count($this->items);
        $names = collect($this->items)->take(3)->map(fn ($i) => "{$i['name']} ({$i['available']} left)")->implode(', ');

        return [
            'kind' => 'low_stock',
            'level' => 'warning',
            'title' => $count === 1 ? 'Low stock' : "{$count} medicines low on stock",
            'message' => $names.($count > 3 ? ' and '.($count - 3).' more' : '').'. Time to reorder.',
            'link' => '/app/inventory?filter=low_stock',
            'meta' => ['items' => $this->items],
        ];
    }
}
