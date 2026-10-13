<?php

namespace App\Notifications;

use App\Models\PlanChangeRequest;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells an organization what happened to its plan change request:
 * approved and applied, approved for the end of the paid period, rejected, or the scheduled change now applied.
 */
class PlanChangeDecided extends AppNotification
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

    private function summary(): array
    {
        $r = $this->request;
        $plan = $r->toPlan->name;

        return match ($r->status) {
            'applied' => ['success', "Your plan is now {$plan}", "{$r->tenant->name} is now on the {$plan} plan."],
            'scheduled' => ['info', 'Plan change approved', "{$r->tenant->name} moves to the {$plan} plan on ".$r->effective_at?->format('j M Y').'. Until then you keep everything in your current plan.'],
            'rejected' => ['danger', 'Plan change not approved', "Your request to move to the {$plan} plan was not approved.".($r->admin_note ? " Reason: {$r->admin_note}" : '')],
            default => ['info', 'Plan change '.$r->status, "Your request to move to the {$plan} plan is {$r->status}."],
        };
    }

    /** The bell notification appears straight away, even when no queue worker is running; the email still goes through the queue. */
    public function viaConnections(): array
    {
        return [TenantDatabaseChannel::class => 'sync'];
    }

    protected function payload(object $notifiable): array
    {
        [$level, $title, $message] = $this->summary();

        return [
            'kind' => 'plan_change',
            'level' => $level,
            'title' => $title,
            'message' => $message,
            'link' => '/app/subscription',
            'meta' => ['plan_change_request_id' => $this->request->id, 'status' => $this->request->status],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$level, $title, $message] = $this->summary();
        $site = rtrim((string) config('app.frontend_url'), '/');
        $mail = (new MailMessage)->subject($title.' - '.config('app.name'))->greeting("Hello {$notifiable->name},")->line($message);
        if ($level === 'danger') {
            $mail->error();
        }
        if ($this->request->status === 'applied' && $this->request->direction === 'upgrade') {
            $mail->line('The new features are switched on now. Your next payment is at the new monthly price.');
        }
        if ($this->request->admin_note && $this->request->status !== 'rejected') {
            $mail->line("**Note from MedLink:** {$this->request->admin_note}");
        }

        return $mail->action('Open your subscription', "{$site}/login");
    }
}
