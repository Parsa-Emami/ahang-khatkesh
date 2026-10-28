<?php

namespace App\Http\Controllers\Api;

use App\Actions\LockCurrentAccount;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class MfaController extends Controller
{
    public function __construct(private LockCurrentAccount $accounts) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => ['enabled' => $request->user()->hasEnabledTwoFactorAuthentication(), 'pending' => $request->user()->two_factor_secret !== null && ! $request->user()->hasEnabledTwoFactorAuthentication()]]);
    }

    public function store(Request $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        return DB::transaction(function () use ($request, $enable): JsonResponse {
            $user = $this->accounts->handle($request->user(), (int) $request->session()->get('auth.access_version', -1));
            abort_unless($user->is_active, 403);
            abort_if($user->hasEnabledTwoFactorAuthentication(), 409, 'تأیید دومرحله‌ای قبلاً فعال شده است.');
            $enable($user);
            AuditEvent::create(['user_id' => $user->id, 'action' => 'auth.mfa_enrollment_started', 'subject_id' => (string) $user->id]);

            return response()->json(['data' => ['setup_key' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret), 'qr_svg' => $user->twoFactorQrCodeSvg()]]);
        }, 5);
    }

    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        return DB::transaction(function () use ($request, $confirm, $data): JsonResponse {
            $user = $this->accounts->handle($request->user(), (int) $request->session()->get('auth.access_version', -1));
            abort_unless($user->is_active, 403);
            $confirm($user, $data['code']);
            $user->forceFill(['access_version' => $user->access_version + 1, 'remember_token' => Str::random(60)])->save();
            auth()->guard('web')->setUser($user);
            $this->revokeOtherSessions($request, $user);
            $request->session()->put('auth.access_version', $user->access_version);
            $request->session()->put(['auth.mfa_authenticated' => true, 'auth.mfa_confirmed_at' => now()->timestamp]);
            $request->session()->passwordConfirmed();
            AuditEvent::create(['user_id' => $user->id, 'action' => 'auth.mfa_confirmed', 'subject_id' => (string) $user->id]);

            return response()->json(['data' => ['recovery_codes' => $user->recoveryCodes(), 'user' => new UserResource($user)]]);
        }, 5);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $user = $this->accounts->handle($request->user(), (int) $request->session()->get('auth.access_version', -1));

            return response()->json(['data' => ['recovery_codes' => $user->recoveryCodes()]]);
        }, 5);
    }

    public function regenerate(Request $request, GenerateNewRecoveryCodes $generate): JsonResponse
    {
        return DB::transaction(function () use ($request, $generate): JsonResponse {
            $user = $this->accounts->handle($request->user(), (int) $request->session()->get('auth.access_version', -1));
            $generate($user);
            $user->forceFill(['access_version' => $user->access_version + 1, 'remember_token' => Str::random(60)])->save();
            auth()->guard('web')->setUser($user);
            $this->revokeOtherSessions($request, $user);
            $request->session()->put('auth.access_version', $user->access_version);
            AuditEvent::create(['user_id' => $user->id, 'action' => 'auth.mfa_recovery_rotated', 'subject_id' => (string) $user->id]);

            return response()->json(['data' => ['recovery_codes' => $user->recoveryCodes(), 'user' => new UserResource($user)]]);
        }, 5);
    }

    public function destroy(Request $request, DisableTwoFactorAuthentication $disable): JsonResponse
    {
        return DB::transaction(function () use ($request, $disable): JsonResponse {
            $user = $this->accounts->handle($request->user(), (int) $request->session()->get('auth.access_version', -1));
            abort_if($user->role->allows(Permission::AdminAccess), 422, 'برای نقش مدیریتی، تأیید دومرحله‌ای الزامی است.');
            $disable($user);
            $user->forceFill(['access_version' => $user->access_version + 1, 'remember_token' => Str::random(60)])->save();
            $this->revokeOtherSessions($request, $user);
            $request->session()->put('auth.access_version', $user->access_version);
            $request->session()->forget(['auth.mfa_authenticated', 'auth.mfa_confirmed_at']);
            AuditEvent::create(['user_id' => $user->id, 'action' => 'auth.mfa_disabled', 'subject_id' => (string) $user->id]);

            return response()->json(['data' => ['user' => new UserResource($user)]]);
        }, 5);
    }

    public function confirmPassword(Request $request, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:128'], 'code' => ['nullable', 'digits:6']]);
        $snapshot = $request->user();
        $observed = User::findOrFail($snapshot->id);
        abort_unless($observed->is_active && $observed->access_version === $snapshot->access_version && $observed->access_version === (int) $request->session()->get('auth.access_version', -1), 401);
        if (! Hash::check($data['password'], $observed->password)) {
            throw ValidationException::withMessages(['password' => 'رمز فعلی درست نیست.']);
        }
        $mfa = DB::transaction(function () use ($snapshot, $observed, $request, $provider, $data): bool {
            $fresh = $this->accounts->handle($snapshot, (int) $request->session()->get('auth.access_version', -1));
            abort_unless(hash_equals($observed->password, $fresh->password), 401);
            if ($fresh->hasEnabledTwoFactorAuthentication() && (empty($data['code']) || ! $provider->verify(Fortify::currentEncrypter()->decrypt($fresh->two_factor_secret), $data['code']))) {
                throw ValidationException::withMessages(['code' => 'کد دومرحله‌ای درست نیست یا قبلاً استفاده شده است.']);
            }
            AuditEvent::create(['user_id' => $fresh->id, 'action' => 'auth.password_confirmed', 'subject_id' => (string) $fresh->id]);

            return $fresh->hasEnabledTwoFactorAuthentication();
        }, 5);
        if ($mfa) {
            $request->session()->put(['auth.mfa_authenticated' => true, 'auth.mfa_confirmed_at' => now()->timestamp]);
        }
        $request->session()->passwordConfirmed();

        return response()->json(['data' => ['confirmed_until' => now()->addSeconds(config('security.admin_confirmation_seconds'))->toIso8601String()]]);
    }

    private function revokeOtherSessions(Request $request, User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }
    }
}
