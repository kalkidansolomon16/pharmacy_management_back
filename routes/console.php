<?php

use Illuminate\Support\Facades\Schedule;

// Daily housekeeping at 06:00 Addis Ababa time, before pharmacies open
Schedule::command('pharmacy:scan')->dailyAt('06:00')->timezone('Africa/Addis_Ababa')->withoutOverlapping();

// Keep the queue table tidy
Schedule::command('queue:prune-failed --hours=168')->weekly();
