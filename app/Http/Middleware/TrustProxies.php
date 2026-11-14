<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * The API container is only reachable through the host nginx (via Docker's
     * port proxy), so requests arrive from loopback or the Docker bridge. nginx
     * puts the real client IP (from Arvan's ar-real-ip, see
     * /etc/nginx/conf.d/arvan-realip.conf) at the end of X-Forwarded-For.
     * Without this every visitor looked like 172.18.0.1 and shared one
     * rate-limit bucket.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = ['127.0.0.1', '::1', '172.16.0.0/12'];

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
