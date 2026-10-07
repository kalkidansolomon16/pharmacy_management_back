<?php

namespace App\Notifications;

use App\Models\Order;

class NewOrderReceived extends AppNotification
{
    public function __construct(public Order $order)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        return [
            'kind' => 'order_new',
            'level' => 'success',
            'title' => 'New online order',
            'message' => "{$this->order->order_number} from {$this->order->customer_name} - ETB ".number_format((float) $this->order->total_amount, 2),
            'link' => "/app/orders/{$this->order->id}",
            'meta' => ['order_id' => $this->order->id],
        ];
    }
}
