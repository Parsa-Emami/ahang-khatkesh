<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['message' => 'برای استفاده از پنل مدیریت، تأیید دومرحله‌ای را فعال کنید.', 'code' => 'mfa_enrollment_required'], 428);
        }
        if (! $request->session()->get('auth.mfa_authenticated', false)) {
            return response()->json(['message' => 'ورود دومرحله‌ای را تکمیل کنید.', 'code' => 'mfa_authentication_required'], 401);
        }

        return $next($request);
    }
}
