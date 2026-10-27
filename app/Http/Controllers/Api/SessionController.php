<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(12)->letters()->numbers(), 'max:128'],
        ]);
        $request->user()->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60), 'access_version' => $request->user()->access_version + 1])->save();
        if (config('session.driver') === 'database') {
            DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
        }
        $request->session()->regenerate(true);
        $request->session()->put('auth.access_version', $request->user()->access_version);
        $request->session()->passwordConfirmed();

        return response()->json(['data' => ['csrf_token' => csrf_token(), 'message' => 'رمز عبور تغییر کرد.']]);
    }
}
