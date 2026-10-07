<?php

namespace App\Notifications;

use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Base class: every in-app notification is queued and stored with a common shape
 * { kind, level, title, message, link, meta } that the frontend renders directly.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    abstract protected function payload(object $notifiable): array;

    public function toArray(object $notifiable): array
    {
        return array_merge(['level' => 'info', 'link' => null, 'meta' => []], $this->payload($notifiable));
    }
}
