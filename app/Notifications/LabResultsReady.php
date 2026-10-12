<?php

namespace App\Notifications;

use App\Models\LabOrder;

class LabResultsReady extends AppNotification
{
    public function __construct(public LabOrder $order)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        $abnormal = $this->order->items->whereNotIn('flag', ['normal', 'negative', null])->count();

        return [
            'kind' => 'lab_results',
            'level' => $abnormal ? 'warning' : 'success',
            'title' => 'Lab results ready',
            'message' => "{$this->order->patient->full_name} - {$this->order->order_number}: ".$this->order->items->count().' test(s)'.($abnormal ? ", {$abnormal} abnormal" : ', all normal').'.',
            'link' => $this->order->visit_id ? "/app/visits/{$this->order->visit_id}" : '/app/lab',
            'meta' => ['lab_order_id' => $this->order->id],
        ];
    }
}
