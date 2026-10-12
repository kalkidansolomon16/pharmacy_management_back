<?php

namespace App\Notifications;

use App\Models\LabOrderItem;

class CriticalLabResult extends AppNotification
{
    public function __construct(public LabOrderItem $item)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        $value = $this->item->result_value !== null ? $this->item->result_value.' '.$this->item->unit : $this->item->result_text;

        return [
            'kind' => 'lab_critical',
            'level' => 'danger',
            'title' => 'CRITICAL lab value',
            'message' => "{$this->item->order->patient->full_name}: {$this->item->test->name} = {$value}. Review immediately.",
            'link' => $this->item->order->visit_id ? "/app/visits/{$this->item->order->visit_id}" : '/app/lab',
            'meta' => ['lab_order_id' => $this->item->lab_order_id],
        ];
    }
}
