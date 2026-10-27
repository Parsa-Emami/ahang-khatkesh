<?php

namespace App\Providers;

use App\Actions\BeginMfaChallenge;
use App\Enums\Permission;
use App\Http\Requests\LoginRequest;
use App\Http\Responses\SessionLoginResponse;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
        $this->app->bind(\Laravel\Fortify\Http\Requests\LoginRequest::class, LoginRequest::class);
        $this->app->singleton(LoginResponse::class, SessionLoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponse::class, SessionLoginResponse::class);
        $this->app->bind(RedirectsIfTwoFactorAuthenticatable::class, BeginMfaChallenge::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        Event::listen([Failed::class, TwoFactorAuthenticationFailed::class], function (object $event): void {
            AuditEvent::create(['user_id' => $event->user?->id, 'action' => $event instanceof Failed ? 'auth.login_failed' : 'auth.mfa_failed', 'subject_type' => 'user', 'subject_id' => $event->user ? (string) $event->user->id : null]);
        });
        Gate::define('admin', fn (User $user) => $user->hasPermission(Permission::AdminAccess));
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->hasPermission($permission));
        }
        Fortify::authenticateUsing(function (Request $request) {
            return (new Timebox)->call(function () use ($request) {
                $user = User::where('email', mb_strtolower(trim($request->string('email')->toString())))->first();
                if (! $user || ! Hash::check($request->string('password')->toString(), $user->password) || ! $user->is_active) {
                    return null;
                }
                if (Hash::needsRehash($user->password)) {
                    $user->forceFill(['password' => Hash::make($request->string('password')->toString())])->save();
                }

                return $user;
            }, 200000);
        });
        RateLimiter::for('two-factor', fn (Request $request) => [Limit::perMinute(5)->by('account:'.(string) $request->session()->get('login.id')), Limit::perMinute(20)->by('ip:'.$request->ip())]);
        RateLimiter::for('confirm-password', fn (Request $request) => Limit::perMinute(5)->by($request->user()->id.'|'.$request->ip()));
        RateLimiter::for('login', fn (Request $request) => [Limit::perMinute(5)->by(hash('sha256', mb_strtolower($request->string('email')->toString()).'|'.$request->ip())), Limit::perMinute(10)->by('account:'.hash('sha256', mb_strtolower($request->string('email')->toString()))), Limit::perMinute(30)->by('ip:'.$request->ip())]);
        RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute(180)->by($request->user()->id));
        RateLimiter::for('upload', fn (Request $request) => Limit::perMinute(30)->by($request->user()->id));
    }
}
