<?php

namespace Tests\Feature\Routing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminRoutePermissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Admin routes that are intentionally public (sign-in and legacy helpers).
     * They start with api/v1/admin/ but must not require a permission.
     *
     * @var array<string, array<string>>
     */
    protected array $publicAdminRoutes = [
        'POST api/v1/admin/login',
    ];

    public function test_every_admin_route_requires_a_permission()
    {
        $routes = collect(Route::getRoutes())->filter(function ($route) {
            return Str::startsWith($route->uri(), 'api/v1/admin/');
        });

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'])) {
                    continue;
                }

                $key = strtoupper($method) . ' ' . $route->uri();
                if (in_array($key, $this->publicAdminRoutes)) {
                    continue;
                }

                $middleware = $route->gatherMiddleware();
                $hasPermission = collect($middleware)->contains(function ($item) {
                    return Str::startsWith($item, 'permission:');
                });

                $this->assertTrue(
                    $hasPermission,
                    "Route {$key} lacks a permission middleware."
                );
            }
        }
    }

    public function test_no_admin_uri_is_registered_more_than_once_per_method()
    {
        $seen = [];

        foreach (Route::getRoutes() as $route) {
            if (! Str::startsWith($route->uri(), 'api/v1/admin/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'])) {
                    continue;
                }

                $key = strtoupper($method) . ' ' . $route->uri();
                $this->assertArrayNotHasKey(
                    $key,
                    $seen,
                    "Duplicate admin route registration: {$key}"
                );
                $seen[$key] = true;
            }
        }

        $this->assertNotEmpty($seen);
    }
}
