<?php

namespace App\Notifications;

use App\Models\Tenant;

class NewTenantRegistered extends AppNotification
{
    /** A rejected organization corrected its details and asks for review again. */
    public bool $resubmitted = false;

    public ?string $note = null;

    // Plain properties with defaults, so notifications queued before these existed still work
    public function __construct(public Tenant $tenant, bool $resubmitted = false, ?string $note = null)
    {
        parent::__construct();
        $this->resubmitted = $resubmitted;
        $this->note = $note;
    }

    protected function payload(object $notifiable): array
    {
        return [
            'kind' => 'tenant_new',
            'level' => 'info',
            'title' => $this->resubmitted ? ucfirst($this->tenant->type).' resubmitted for approval' : 'New '.$this->tenant->type.' awaiting approval',
            'message' => $this->resubmitted
                ? "{$this->tenant->name} corrected its details (licence {$this->tenant->license_number}) after being rejected.".($this->note ? " Note: {$this->note}" : '')
                : "{$this->tenant->name} ({$this->tenant->city}) registered with licence {$this->tenant->license_number}.",
            'link' => '/app/admin/tenants?status=pending',
            'meta' => ['tenant_id' => $this->tenant->id],
        ];
    }
}
