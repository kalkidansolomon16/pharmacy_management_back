<?php

namespace App\Notifications;

use App\Models\Order;

class OrderStatusChanged extends AppNotification
{
    private const MESSAGES = [
        'confirmed' => ['success', 'Order confirmed', 'The pharmacy confirmed your order and is preparing it.'],
        'ready' => ['success', 'Ready for pickup', 'Your medicines are ready. Please bring your ID / prescription.'],
        'completed' => ['success', 'Order completed', 'Your order has been dispensed. Get well soon!'],
        'partially_completed' => ['warning', 'Order partially fulfilled', 'Some items were not fully available. You were charged only for what was dispensed.'],
        'rejected' => ['danger', 'Order rejected', 'The pharmacy could not accept your order.'],
        'cancelled' => ['danger', 'Order cancelled', 'Your order has been cancelled.'],
    ];

    public function __construct(public Order $order)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        [$level, $title, $message] = self::MESSAGES[$this->order->status] ?? ['info', 'Order updated', 'Your order status changed.'];
        $pharmacy = $this->order->pharmacy()->value('name');

        return [
            'kind' => 'order_status',
            'level' => $level,
            'title' => $title,
            'message' => "{$this->order->order_number} at {$pharmacy}: {$message}"
                .($this->order->cancel_reason ? " Reason: {$this->order->cancel_reason}" : ''),
            'link' => "/account/orders/{$this->order->id}",
            'meta' => ['order_id' => $this->order->id, 'status' => $this->order->status],
        ];
    }
}
