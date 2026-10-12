<?php

use Illuminate\Support\Facades\Schedule;

// Daily housekeeping at 06:00 Addis Ababa time, before pharmacies open
Schedule::command('pharmacy:scan')->dailyAt('06:00')->timezone('Africa/Addis_Ababa')->withoutOverlapping();

// Keep the queue table tidy
Schedule::command('queue:prune-failed --hours=168')->weekly();

// Register invoices that could not reach the Ministry of Revenue (offline, outage)
Schedule::command('einvoice:sync')->everyFiveMinutes()->withoutOverlapping();

// Encrypted database backup every night
Schedule::command('system:backup')->dailyAt('02:00')->timezone('Africa/Addis_Ababa')->withoutOverlapping();

// Subscription expiry reminders / lapses
Schedule::command('subscriptions:check')->dailyAt('07:00')->timezone('Africa/Addis_Ababa');
