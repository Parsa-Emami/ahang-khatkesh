<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireGuestSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            return response()->json(['message' => __('یک نشست فعال دارید؛ ابتدا از حساب خارج شوید.'), 'code' => 'already_authenticated'], 409);
        }

        return $next($request);
    }
}
