<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration (API client) tokens carry limited abilities such as "fhir:read".
 * They may only call the interoperability endpoints, never the staff application API.
 */
class RestrictIntegrationTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->exists && ! in_array('*', (array) $token->abilities, true)) {
            return response()->json(['message' => 'This integration token can only be used with the FHIR API.'], 403);
        }

        return $next($request);
    }
}
