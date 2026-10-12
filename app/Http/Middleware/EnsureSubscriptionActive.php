<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a subscription lapses (after the grace period) the organization keeps read access to its data
 * but cannot record new transactions until it renews. Organizations without a subscription record
 * (e.g. created before SaaS billing) are not restricted.
 */
class EnsureSubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $subscription = $request->user()?->tenant?->billingSubscription(); // a branch follows its head office

        if ($subscription && ! $request->isMethodSafe() && ! $subscription->isUsable()) {
            return response()->json([
                'message' => 'Your MedLink subscription has expired. Your data is safe and readable - renew to continue recording transactions.',
                'code' => 'subscription_expired',
            ], 402);
        }

        return $next($request);
    }
}
