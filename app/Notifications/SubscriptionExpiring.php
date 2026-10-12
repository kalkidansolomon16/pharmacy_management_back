<?php

namespace App\Notifications;

use App\Models\Subscription;

class SubscriptionExpiring extends AppNotification
{
    public function __construct(public Subscription $subscription)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        $days = $this->subscription->daysLeft();

        return [
            'kind' => 'subscription',
            'level' => 'warning',
            'title' => 'Subscription ending soon',
            'message' => "Your {$this->subscription->plan->name} ".($this->subscription->status === 'trial' ? 'trial' : 'plan')." ends in {$days} day(s). Renew to keep recording sales and visits.",
            'link' => '/app/subscription',
        ];
    }
}
