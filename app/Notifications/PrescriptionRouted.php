<?php

namespace App\Notifications;

use App\Models\Prescription;

class PrescriptionRouted extends AppNotification
{
    public function __construct(public Prescription $prescription)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        return [
            'kind' => 'prescription_routed',
            'level' => 'info',
            'title' => 'New e-prescription',
            'message' => "{$this->prescription->reference_code} for {$this->prescription->patient->full_name} from {$this->prescription->hospital->name} - ".$this->prescription->items->count().' item(s).',
            'link' => '/app/rx-inbox',
            'meta' => ['reference_code' => $this->prescription->reference_code],
        ];
    }
}
