<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Payer;
use App\Services\Billing\InsuranceService;

class ReceivablesController extends Controller
{
    public function __invoke(InsuranceService $insurance)
    {
        $this->authorize('viewAny', Payer::class);

        return $this->respond($insurance->aging());
    }
}
