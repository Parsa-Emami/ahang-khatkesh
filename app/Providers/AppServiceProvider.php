<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        Gate::define('admin', fn ($user) => $user->is_active && $user->is_admin);
        RateLimiter::for('login', fn (Request $request) => [Limit::perMinute(5)->by(hash('sha256', mb_strtolower($request->string('email')->toString()).'|'.$request->ip())), Limit::perMinute(30)->by($request->ip())]);
        RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute(180)->by($request->user()->id));
        RateLimiter::for('upload', fn (Request $request) => Limit::perMinute(30)->by($request->user()->id));
    }
}
