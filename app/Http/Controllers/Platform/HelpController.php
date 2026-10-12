<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;

class HelpController extends Controller
{
    public function __invoke()
    {
        return $this->respond([
            'support' => array_filter(config('medlink.support')),
            'shortcuts' => [
                ['keys' => 'Alt + S', 'action' => 'New counter sale (POS)'],
                ['keys' => 'Alt + Q', 'action' => 'Patient queue'],
                ['keys' => 'Alt + P', 'action' => 'Patients'],
                ['keys' => 'Alt + I', 'action' => 'Inventory'],
                ['keys' => 'Alt + B', 'action' => 'Billing / cashier'],
                ['keys' => 'Alt + D', 'action' => 'Dashboard'],
                ['keys' => '/', 'action' => 'Focus the search box'],
                ['keys' => '?', 'action' => 'Show keyboard shortcuts'],
            ],
        ]);
    }
}
