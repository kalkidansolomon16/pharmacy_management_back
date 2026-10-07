<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public endpoints act for no tenant: they filter explicitly on public / active data instead.
 */
class PublicContext
{
    public function __construct(private TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->tenants->run(null, fn () => $next($request));
    }
}
