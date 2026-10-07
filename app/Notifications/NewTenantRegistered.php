<?php

namespace App\Notifications;

use App\Models\Tenant;

class NewTenantRegistered extends AppNotification
{
    public function __construct(public Tenant $tenant)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        return [
            'kind' => 'tenant_new',
            'level' => 'info',
            'title' => 'New '.$this->tenant->type.' awaiting approval',
            'message' => "{$this->tenant->name} ({$this->tenant->city}) registered with licence {$this->tenant->license_number}.",
            'link' => '/app/admin/tenants?status=pending',
            'meta' => ['tenant_id' => $this->tenant->id],
        ];
    }
}
