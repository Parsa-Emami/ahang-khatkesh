<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        Context::add('request_id', $requestId);
        $response = $next($request);
        foreach (['X-Request-ID' => $requestId, 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'same-origin', 'X-Frame-Options' => 'DENY', 'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()'] as $key => $value) {
            $response->headers->set($key, $value);
        }
        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        if (app()->isProduction()) {
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        }

        return $response;
    }
}
