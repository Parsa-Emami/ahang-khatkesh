<?php

namespace App\Actions;

use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

class BeginMfaChallenge extends RedirectIfTwoFactorAuthenticatable
{
    protected function twoFactorChallengeResponse($request, $user)
    {
        $request->session()->regenerate(true);
        $request->session()->forget('auth');
        $request->session()->put(['login.started_at' => now()->timestamp, 'login.access_version' => $user->access_version]);
        parent::twoFactorChallengeResponse($request, $user);

        return response()->json(['data' => ['two_factor_required' => true, 'csrf_token' => csrf_token()]]);
    }
}
