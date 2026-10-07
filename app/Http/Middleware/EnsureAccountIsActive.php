<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks suspended users, and users whose organization is not (yet) active.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== 'active') {
            return response()->json(['message' => 'Your account is '.$user->status.'. Contact your administrator.', 'code' => 'account_inactive'], 403);
        }

        $tenant = $user?->tenant;
        if ($tenant && ! $tenant->isActive()) {
            $message = $tenant->status === 'pending'
                ? "{$tenant->name} is awaiting verification by the MedLink team. You will be notified once approved."
                : "{$tenant->name} is {$tenant->status}. Contact support for assistance.";

            return response()->json(['message' => $message, 'code' => 'tenant_'.$tenant->status], 403);
        }

        return $next($request);
    }
}
