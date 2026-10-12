<?php

namespace App\Console\Commands;

use App\Services\Platform\SubscriptionService;
use Illuminate\Console\Command;

class CheckSubscriptions extends Command
{
    protected $signature = 'subscriptions:check';

    protected $description = 'Mark lapsed subscriptions past due and remind admins before they end';

    public function handle(SubscriptionService $subscriptions): int
    {
        $result = $subscriptions->check();
        $this->info("Past due: {$result['past_due']}, reminders sent: {$result['reminded']}.");

        return self::SUCCESS;
    }
}
