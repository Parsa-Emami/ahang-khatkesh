<?php

namespace App\Http\Controllers\Api;

use App\Actions\ChangeOwnPassword;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user && (! $user->is_active || (int) $request->session()->get('auth.access_version', -1) !== $user->access_version)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $user = null;
        }

        return response()->json(['data' => ['csrf_token' => csrf_token(), 'user' => $user?->is_active ? new UserResource($user) : null]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'auth.logout', 'subject_type' => 'user', 'subject_id' => (string) $request->user()->id]);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['csrf_token' => csrf_token()]]);
    }

    public function update(Request $request): UserResource
    {
        $data = $request->validate(['theme' => ['required', Rule::in(['light', 'dark', 'system'])]]);
        $request->user()->update($data);

        return new UserResource($request->user());
    }

    public function password(Request $request, ChangeOwnPassword $change): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(12)->letters()->numbers(), 'max:128'],
        ]);
        $user = $change->handle($request->user(), (int) $request->session()->get('auth.access_version', -1), $data['current_password'], $data['password'], $request->session()->getId());
        Auth::guard('web')->setUser($user);
        $request->session()->regenerate(true);
        $request->session()->put('auth.access_version', $user->access_version);
        $request->session()->passwordConfirmed();

        return response()->json(['data' => ['csrf_token' => csrf_token(), 'message' => 'رمز عبور تغییر کرد.', 'user' => new UserResource($user)]]);
    }
}
