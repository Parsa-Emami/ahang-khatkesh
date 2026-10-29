<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $language = $request->getPreferredLanguage(['fa-IR', 'en-US', 'fa', 'en']);
        app()->setLocale(str_starts_with($language ?? '', 'en') ? 'en' : 'fa');
        $response = $next($request);
        if ($request->is('api/*')) {
            $response->headers->set('Content-Language', app()->getLocale() === 'en' ? 'en-US' : 'fa-IR');
            $response->setVary('Accept-Language', false);
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
