<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\Auth\AuthService;
use Firebase\JWT\ExpiredException;
use Illuminate\Support\Facades\Auth;

class JwtVerify
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\JsonResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $authorizationHeader = explode(' ', $request->header('Authorization')); // ['Bearer', 'TOKEN']
        $head = $authorizationHeader[0] ?? false;
        $jwt = $authorizationHeader[1] ?? false;

        if (!$head || !$jwt) {
            return response()->json([
                'status' => 0,
                'reply' => 'Unauthorized'
            ], 200);
        }

        try {

            $decoded = AuthService::verifyToken($jwt);

            $request->attributes->add(['decoded' => $decoded, 'jwt' => $jwt]);

            Auth::loginUsingId($decoded->sub);

            return $next($request);
        } catch (ExpiredException $e) {
            return response()->json([
                'status' => 0,
                'reply' => 'Expired Token!'
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'reply' => 'Invalid User!'
            ], 401);
        }
    }
}
