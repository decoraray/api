<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthService;
use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Support\Facades\Auth;

class Authenticate extends Middleware
{

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    public function handle($request, Closure $next, ...$guards)
    {
        $authorizationHeader = explode(' ', $request->header('Authorization')); // ['Bearer', 'TOKEN']
        $head = $authorizationHeader[0] ?? false;
        $jwt = $authorizationHeader[1] ?? false;

        if (!$head || !$jwt) {
            return $next($request);
        }

        try {
            $decoded = AuthService::verifyToken($jwt);
            Auth::loginUsingId($decoded->sub);
            Auth::guard('api')->loginUsingId($decoded->sub);
            return $next($request);
        } catch (\Exception $e) {
            return $next($request);
        }
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        if (!$request->expectsJson()) {
            return route('login');
        }
    }
}
