<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Notifications\Messages\MailMessage;

/** Tells a patient the hospital cancelled their appointment (in the app and by email). */
class AppointmentCancelled extends AppNotification
{
    public function __construct(public Appointment $appointment)
    {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return [...parent::via($notifiable), ...(! empty($notifiable->email) ? ['mail'] : [])];
    }

    protected function payload(object $notifiable): array
    {
        $a = $this->appointment->loadMissing('hospital');

        return [
            'kind' => 'appointment_cancelled',
            'level' => 'warning',
            'title' => 'Appointment cancelled',
            'message' => "{$a->hospital?->name} cancelled your appointment on {$a->scheduled_at->format('D d M, H:i')}.".($a->cancel_reason ? " Reason: {$a->cancel_reason}" : '').' Please book another time.',
            'link' => null,
            'meta' => ['appointment_id' => $a->id],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->appointment->loadMissing('hospital');

        return (new MailMessage)
            ->subject("Your appointment at {$a->hospital?->name} was cancelled")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$a->hospital?->name} cancelled your appointment **{$a->reference}** on **{$a->scheduled_at->format('l d M Y, H:i')}**.")
            ->lineIf((bool) $a->cancel_reason, "**Reason:** {$a->cancel_reason}")
            ->line('Please open the MedLink app to book another time or another hospital nearby.');
    }
}
