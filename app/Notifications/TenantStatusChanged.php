<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells an organization's team it was approved, rejected or suspended - in the app and by email.
 */
class TenantStatusChanged extends AppNotification
{
    public function __construct(public Tenant $tenant)
    {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        $channels = parent::via($notifiable);

        // Email as well, so people hear back even if they never open MedLink again
        if (! empty($notifiable->email) && in_array($this->tenant->status, ['active', 'rejected', 'suspended'], true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    protected function payload(object $notifiable): array
    {
        $approved = $this->tenant->status === 'active';
        $reason = $this->tenant->status_reason;

        return [
            'kind' => 'tenant_status',
            'level' => $approved ? 'success' : 'danger',
            'title' => $approved ? 'Organization approved' : 'Organization '.$this->tenant->status,
            'message' => $approved
                ? "{$this->tenant->name} is verified and live on MedLink Ethiopia. Welcome aboard!"
                : "{$this->tenant->name} is currently {$this->tenant->status}.".($reason ? " Reason: {$reason}" : ' Contact support for help.'),
            'link' => $approved ? '/app/dashboard' : '/pending',
            'meta' => ['tenant_id' => $this->tenant->id, 'status' => $this->tenant->status, 'reason' => $reason],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = $this->tenant;
        $app = config('app.name');
        $site = rtrim((string) config('app.frontend_url'), '/');
        $support = config('medlink.support.phone') ?? null;
        $mail = (new MailMessage)->greeting("Hello {$notifiable->name},");

        if ($tenant->status === 'active') {
            return $mail
                ->subject("{$tenant->name} is approved on {$app}")
                ->line("Good news! We verified the licence of **{$tenant->name}** ({$tenant->license_number}) and your organization is now live on {$app}.")
                ->line('You can now add your team, set up your '.($tenant->isPharmacy() ? 'stock and prices' : 'services and patients').', and start working.')
                ->action('Open your dashboard', "{$site}/login")
                ->line('Your first month is free on every plan.');
        }

        $mail->error();
        if ($tenant->status === 'rejected') {
            $mail->subject("Your {$app} registration for {$tenant->name} needs attention")
                ->line("We could not approve **{$tenant->name}** yet.");
        } else {
            $mail->subject("{$tenant->name} has been suspended on {$app}")
                ->line("Access for **{$tenant->name}** has been suspended.");
        }
        if ($tenant->status_reason) {
            $mail->line("**Reason:** {$tenant->status_reason}");
        }
        if ($tenant->status === 'rejected') {
            $mail->line('Sign in to correct your details and send them back for review.')
                ->action('Fix and resubmit', "{$site}/login");
        }

        return $mail->line($support ? "Questions? Call MedLink support on {$support}." : 'Questions? Reply to this email or contact MedLink support.');
    }
}
