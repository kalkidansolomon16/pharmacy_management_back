<?php

namespace App\Notifications;

use App\Models\Prescription;

class PrescriptionIssued extends AppNotification
{
    public function __construct(public Prescription $prescription)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        return [
            'kind' => 'prescription',
            'level' => 'info',
            'title' => 'New prescription',
            'message' => "Prescription {$this->prescription->reference_code} is ready. Valid until {$this->prescription->expires_at->toDateString()}.",
            'link' => '/prescription-check?code='.$this->prescription->reference_code,
            'meta' => ['reference_code' => $this->prescription->reference_code],
        ];
    }
}
