<?php

namespace App\Notifications;

use App\Models\PlanChangeRequest;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Messages\MailMessage;

/** Tells the platform team an organization wants to change plan - in the app and by email. */
class PlanChangeRequested extends AppNotification
{
    public function __construct(public PlanChangeRequest $request)
    {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        $channels = parent::via($notifiable);
        if (! empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /** The bell notification appears straight away, even when no queue worker is running; the email still goes through the queue. */
    public function viaConnections(): array
    {
        return [TenantDatabaseChannel::class => 'sync'];
    }

    protected function payload(object $notifiable): array
    {
        $r = $this->request;

        return [
            'kind' => 'plan_change',
            'level' => 'info',
            'title' => ucfirst($r->direction).' requested',
            'message' => "{$r->tenant->name} asks to move from ".($r->fromPlan?->name ?? 'no plan')." to {$r->toPlan->name}.".($r->note ? " Note: {$r->note}" : ''),
            'link' => '/app/admin/billing#plan-requests',
            'meta' => ['plan_change_request_id' => $r->id, 'tenant_id' => $r->tenant_id],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->request;
        $site = rtrim((string) config('app.frontend_url'), '/');
        $mail = (new MailMessage)
            ->subject("{$r->tenant->name} requests a plan {$r->direction}")
            ->greeting("Hello {$notifiable->name},")
            ->line("**{$r->tenant->name}** asks to move from **".($r->fromPlan?->name ?? 'no plan')."** to **{$r->toPlan->name}** (ETB ".number_format((float) $r->toPlan->price_monthly).' / month).');
        if ($r->requester) {
            $mail->line("Requested by {$r->requester->name}.");
        }
        if ($r->note) {
            $mail->line("**Note:** {$r->note}");
        }

        return $mail->action('Review the request', "{$site}/app/admin/billing");
    }
}
