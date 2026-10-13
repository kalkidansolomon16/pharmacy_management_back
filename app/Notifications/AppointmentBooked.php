<?php

namespace App\Notifications;

use App\Models\Appointment;

/** Tells reception a patient booked an appointment online. */
class AppointmentBooked extends AppNotification
{
    public function __construct(public Appointment $appointment)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        $a = $this->appointment;

        return [
            'kind' => 'appointment_new',
            'level' => 'info',
            'title' => 'New appointment booked',
            'message' => "{$a->patient_name} booked {$a->scheduled_at->format('D d M, H:i')} ({$a->reference}): {$a->reason}",
            'link' => '/app/appointments?date='.$a->scheduled_at->toDateString(),
            'meta' => ['appointment_id' => $a->id],
        ];
    }
}
