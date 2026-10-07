<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: tenant.type:pharmacy / tenant.type:hospital
 */
class EnsureTenantType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        if ($request->user()?->tenant?->type !== $type) {
            return response()->json(['message' => "This area is only available to {$type} accounts."], 403);
        }

        return $next($request);
    }
}
