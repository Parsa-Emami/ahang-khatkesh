<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $stamp = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $age = now()->timestamp - $stamp;
        $mfaAge = now()->timestamp - (int) $request->session()->get('auth.mfa_confirmed_at', 0);
        $mfaExpired = $request->user()->hasEnabledTwoFactorAuthentication() && ($mfaAge < 0 || $mfaAge >= config('security.admin_confirmation_seconds'));
        if ($stamp <= 0 || $age < 0 || $age >= config('security.admin_confirmation_seconds') || $mfaExpired) {
            return response()->json(['message' => __('برای ادامهٔ عملیات، رمز عبورتان را دوباره تأیید کنید.'), 'code' => 'password_confirmation_required'], 423);
        }

        return $next($request);
    }
}
