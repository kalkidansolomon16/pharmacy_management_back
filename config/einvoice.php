<?php

/*
|--------------------------------------------------------------------------
| Electronic invoicing (Ministry of Revenue - Electronic Invoice Registration System)
|--------------------------------------------------------------------------
| Under the e-invoicing directive, every sales invoice issued by approved software is registered
| with the Ministry's system and carries an IRN (invoice reference number), an RRN (receipt
| registration number returned by the Ministry) and a QR code.
|
| driver = sandbox : simulates the Ministry locally (development, demos, tests).
| driver = mor     : calls the Ministry endpoint below. Requires accreditation, credentials and the
|                    official API specification; field names in MorEInvoiceGateway must be checked
|                    against that specification before going live.
*/

return [
    'driver' => env('EINVOICE_DRIVER', 'sandbox'),

    'mor' => [
        'endpoint' => env('EINVOICE_MOR_ENDPOINT'),
        'client_id' => env('EINVOICE_MOR_CLIENT_ID'),
        'client_secret' => env('EINVOICE_MOR_CLIENT_SECRET'),
        'device_id' => env('EINVOICE_DEVICE_ID'),
        'timeout' => (int) env('EINVOICE_TIMEOUT', 10),
    ],

    // Sandbox only: fraction of submissions that fail with a network error, to exercise the retry queue
    'sandbox_failure_rate' => (float) env('EINVOICE_SANDBOX_FAILURE_RATE', 0),

    // Pending registrations are retried by `einvoice:sync` until this many attempts
    'max_attempts' => 12,
];
