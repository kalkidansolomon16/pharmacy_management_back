<?php

namespace App\Notifications;

use App\Models\Tenant;

class TenantStatusChanged extends AppNotification
{
    public function __construct(public Tenant $tenant)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        $approved = $this->tenant->status === 'active';

        return [
            'kind' => 'tenant_status',
            'level' => $approved ? 'success' : 'danger',
            'title' => $approved ? 'Organization approved' : 'Organization '.$this->tenant->status,
            'message' => $approved
                ? "{$this->tenant->name} is verified and live on MedLink Ethiopia. Welcome aboard!"
                : "{$this->tenant->name} is currently {$this->tenant->status}. Contact support for help.",
            'link' => '/app/dashboard',
        ];
    }
}
