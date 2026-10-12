<?php

namespace App\Notifications;

use App\Models\PayerAuthorization;

class AuthorizationRequested extends AppNotification
{
    public function __construct(public PayerAuthorization $authorization)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        return [
            'kind' => 'authorization',
            'level' => 'warning',
            'title' => 'Insurance approval needed',
            'message' => "{$this->authorization->payer->name}: ETB ".number_format((float) $this->authorization->amount, 2)." on {$this->authorization->invoice->invoice_number} needs pre-authorization.",
            'link' => '/app/insurance?tab=approvals',
            'meta' => ['authorization_id' => $this->authorization->id],
        ];
    }
}
