<?php

namespace App\Http\Responses;

use App\Http\Resources\UserResource;
use App\Models\AuditEvent;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;

class SessionLoginResponse implements LoginResponse, TwoFactorLoginResponse
{
    public function toResponse($request)
    {
        $request->session()->passwordConfirmed();
        $request->session()->put('auth.mfa_authenticated', $request->routeIs('api.login.challenge'));
        if ($request->routeIs('api.login.challenge')) {
            $request->session()->put('auth.mfa_confirmed_at', now()->timestamp);
        }
        $request->session()->forget('login');
        AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'auth.login', 'subject_id' => (string) $request->user()->id]);

        return response()->json(['data' => ['csrf_token' => csrf_token(), 'user' => new UserResource($request->user())]]);
    }
}
