<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Reports\OwnerDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Owner view across all branches and departments.
 */
class OwnerController extends Controller
{
    public function __invoke(Request $request, OwnerDashboardService $owner)
    {
        Gate::authorize('owner.dashboard');
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'in:7,30,90']])['days'] ?? 30);

        return $this->respond($owner->for($request->user(), $days));
    }
}
