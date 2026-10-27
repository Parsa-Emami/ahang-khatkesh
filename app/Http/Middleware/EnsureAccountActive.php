<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'این حساب غیرفعال است.');
        }

        if ((int) $request->session()->get('auth.access_version', -1) !== $request->user()->access_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(401, 'دسترسی حساب تغییر کرده است؛ دوباره وارد شوید.');
        }

        return $next($request);
    }
}
