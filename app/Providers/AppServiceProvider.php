<?php

namespace App\Providers;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Report abilities (reports are not models, so they are gates rather than policies)
        Gate::define('reports.sales', fn (User $u) => $u->can('reports.view') && ($u->isSuperAdmin() || $u->tenant?->isPharmacy()));
        Gate::define('reports.inventory', fn (User $u) => $u->can('reports.view') && $u->tenant?->isPharmacy());
        Gate::define('reports.prescriptions', fn (User $u) => ($u->can('reports.view') || $u->can('prescriptions.create')) && $u->tenant?->isHospital());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);

        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Prescription codes are guessable only by brute force: keep this tight
        RateLimiter::for('prescription-verify', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
