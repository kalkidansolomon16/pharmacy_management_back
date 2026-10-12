<?php

return [
    /*
    | Support channels shown in the in-app help centre (leave empty to hide).
    */
    'support' => [
        'phone' => env('SUPPORT_PHONE'),
        'telegram' => env('SUPPORT_TELEGRAM'),   // e.g. https://t.me/your_support_channel
        'email' => env('SUPPORT_EMAIL'),
        'tutorials_url' => env('SUPPORT_TUTORIALS_URL'), // e.g. a YouTube playlist of Amharic tutorials
    ],

    /*
    | Encrypted database backups (`php artisan system:backup`, scheduled daily at 02:00).
    */
    'backup' => [
        'disk_path' => storage_path('app/backups'),
        'keep' => (int) env('BACKUP_KEEP', 14),
        'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    ],

    // Days of free trial for organizations that register themselves
    'trial_days' => 30,
];
