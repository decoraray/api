<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            // Remove rate limiting for admin users (SuperAdmin or Admin roles)
            if ($user && ($user->hasRole('SuperAdmin') || $user->hasRole('Admin'))) {
                return Limit::none();
            }

            // Our own servers (the Nuxt SSR calling the API) render pages for every
            // visitor from one IP; limiting them would throttle the whole site.
            if (in_array($request->ip(), config('app.rate_limit_exempt_ips'), true)) {
                return Limit::none();
            }

            return Limit::perMinute(120)->by($user?->id ?: $request->ip());
        });

        // Short-link resolves come from the storefront SSR (one IP), so our own servers are exempt.
        RateLimiter::for('links', function (Request $request) {
            if (in_array($request->ip(), config('app.rate_limit_exempt_ips'), true)) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('enquiries', fn (Request $request) => Limit::perMinutes(10, 5)->by($request->ip()));
    }
}
