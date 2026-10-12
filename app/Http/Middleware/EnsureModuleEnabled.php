<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: module:pharmacy / module:clinical / module:lab / module:billing / module:insurance
 * An organization only reaches the modules it has switched on (and its subscription plan includes).
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $tenant = $request->user()?->tenant;

        if (! $tenant?->hasModule($module)) {
            return response()->json([
                'message' => "The {$module} module is not enabled for your organization.",
                'code' => 'module_disabled',
            ], 403);
        }

        return $next($request);
    }
}
