<?php

namespace App\Notifications;

class ExpiryAlert extends AppNotification
{
    /**
     * @param  array<int, array{name: string, batch: string, expiry_date: string, quantity: int}>  $expiringSoon
     */
    public function __construct(public array $expiringSoon, public int $expiredToday = 0)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        $parts = [];
        if ($this->expiredToday) {
            $parts[] = "{$this->expiredToday} batch(es) expired and were removed from sellable stock";
        }
        if ($count = count($this->expiringSoon)) {
            $first = $this->expiringSoon[0];
            $parts[] = "{$count} batch(es) expire within 90 days - soonest: {$first['name']} batch {$first['batch']} on {$first['expiry_date']}";
        }

        return [
            'kind' => 'expiry',
            'level' => $this->expiredToday ? 'danger' : 'warning',
            'title' => 'Expiry alert',
            'message' => implode('. ', $parts).'.',
            'link' => '/app/inventory/expiry',
            'meta' => ['expiring' => array_slice($this->expiringSoon, 0, 20), 'expired' => $this->expiredToday],
        ];
    }
}
