<?php

namespace App\Providers;

use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // one per request: which organization the request works inside (see the class)
        $this->app->singleton(OrganizationContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // docs/DEVELOPMENT_PLAN.md §10.3: one sync every 2 minutes is normal; 30/min leaves
        // plenty of room for retries and catch-up batches.
        RateLimiter::for('agent-sync', fn (Request $request) => Limit::perMinute(30)->by((string) $request->user()?->id));

        // Password-reset emails link to the dashboard's own page, not to a Laravel view.
        ResetPassword::createUrlUsing(fn (User $user, string $token) => $user->passwordSetUrl($token));
    }
}
