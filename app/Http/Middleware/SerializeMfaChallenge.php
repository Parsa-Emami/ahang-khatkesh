<?php

namespace App\Http\Middleware;

use App\Models\LibrarySetting;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SerializeMfaChallenge
{
    public function handle(Request $request, Closure $next): Response
    {
        return DB::transaction(function () use ($request, $next): Response {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $user = User::whereKey($request->session()->get('login.id'))->lockForUpdate()->first();
            $age = now()->timestamp - (int) $request->session()->get('login.started_at', 0);
            if (! $user || ! $user->is_active || ! $user->hasEnabledTwoFactorAuthentication() || $age < 0 || $age >= config('security.challenge_seconds') || $user->access_version !== (int) $request->session()->get('login.access_version', -1)) {
                $request->session()->forget(['login', 'auth']);

                return response()->json(['message' => 'درخواست ورود منقضی شده است؛ دوباره وارد شوید.', 'code' => 'challenge_expired'], 422);
            }

            return $next($request);
        }, 5);
    }
}
