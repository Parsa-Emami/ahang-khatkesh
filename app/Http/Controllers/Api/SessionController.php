<?php

namespace App\Http\Controllers\Api;

use App\Actions\ChangeOwnPassword;
use App\Actions\Playback\ManageOfflineDownloads;
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

    public function destroy(Request $request, ManageOfflineDownloads $offline): JsonResponse
    {
        $user = $request->user();
        $offline->wipe($user);
        AuditEvent::create(['user_id' => $user->id, 'action' => 'auth.logout', 'subject_type' => 'user', 'subject_id' => (string) $user->id]);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['csrf_token' => csrf_token(), 'offline_wiped' => true]]);
    }

    public function update(Request $request): UserResource
    {
        $data = $request->validate([
            'theme' => ['sometimes', 'required', Rule::in(['light', 'dark', 'system'])],
            'locale' => ['sometimes', 'required', Rule::in(['fa-IR', 'en-US'])],
            'direction' => ['sometimes', 'required', Rule::in(['auto', 'rtl', 'ltr'])],
            'motion' => ['sometimes', 'required', Rule::in(['system', 'full', 'reduced'])],
            'time_zone' => ['sometimes', 'required', 'string', 'max:64', 'timezone:all'],
            'crossfade_enabled' => ['sometimes', 'boolean'],
            'crossfade_seconds' => ['sometimes', 'integer', 'between:1,12'],
            'crossfade_acknowledged' => ['sometimes', 'boolean'],
            'loudness_normalization' => ['sometimes', 'boolean'],
            'offline_opt_in' => ['sometimes', 'boolean'],
            'offline_revocation_acknowledged' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        if (array_key_exists('crossfade_enabled', $data) && $data['crossfade_enabled'] === true) {
            abort_unless($user->crossfade_acknowledged_at !== null || ($data['crossfade_acknowledged'] ?? false) === true, 422, __('ابتدا محدودیت دستگاه‌ها برای crossfade را تأیید کنید.'));
            if ($user->crossfade_acknowledged_at === null) {
                $data['crossfade_acknowledged_at'] = now();
            }
        }
        if (array_key_exists('crossfade_acknowledged', $data)) {
            if ($data['crossfade_acknowledged']) {
                $data['crossfade_acknowledged_at'] = $user->crossfade_acknowledged_at ?? now();
            }
            unset($data['crossfade_acknowledged']);
        }
        if (array_key_exists('offline_opt_in', $data) && $data['offline_opt_in'] === true) {
            abort_unless(
                $user->offline_revocation_acknowledged_at !== null || ($data['offline_revocation_acknowledged'] ?? false) === true,
                422,
                __('ابتدا محدودیت ابطال روی دستگاه کاملاً آفلاین را تأیید کنید.'),
            );
            if ($user->offline_revocation_acknowledged_at === null) {
                $data['offline_revocation_acknowledged_at'] = now();
            }
        }
        if (array_key_exists('offline_revocation_acknowledged', $data)) {
            if ($data['offline_revocation_acknowledged']) {
                $data['offline_revocation_acknowledged_at'] = $user->offline_revocation_acknowledged_at ?? now();
            }
            unset($data['offline_revocation_acknowledged']);
        }
        $user->update($data);

        return new UserResource($user->fresh());
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

        return response()->json(['data' => ['csrf_token' => csrf_token(), 'message' => __('رمز عبور تغییر کرد.'), 'user' => new UserResource($user)]]);
    }
}
