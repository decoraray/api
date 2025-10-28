<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HealthController extends Controller
{
    /**
     * Health check endpoint
     * Returns basic health status and logs the request
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function check(Request $request)
    {
        $timestamp = now()->toISOString();
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        // Log the health check request
        Log::info('Health check requested', [
            'timestamp' => $timestamp,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'url' => $request->fullUrl(),
            'method' => $request->method()
        ]);

        return response()->json([
            'status' => 'healthy',
            'timestamp' => $timestamp,
            'service' => 'Decoraray API',
            'version' => '1.0.0'
        ], 200);
    }
}
