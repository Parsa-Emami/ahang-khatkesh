<?php

use App\Exceptions\ImportPlanChanged;
use App\Exceptions\MediaNotReady;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\RequireGuestSession;
use App\Http\Middleware\RequireMfa;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SerializeMfaChallenge;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['guest-session' => RequireGuestSession::class, 'active' => EnsureAccountActive::class, 'recent-password' => RequireRecentPassword::class, 'mfa' => RequireMfa::class, 'serialized-mfa' => SerializeMfaChallenge::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (MediaNotReady $error) => response()->json(['message' => $error->getMessage(), 'code' => 'media_not_ready'], 409));
        $exceptions->render(fn (ImportPlanChanged $error) => response()->json(['message' => $error->getMessage(), 'code' => 'import_plan_changed'], 409));
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->respond(function (Response $response): Response {
            $response->headers->set('X-Request-ID', (string) Context::get('request_id'));
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            if (request()->is('api/*')) {
                $response->headers->set('Cache-Control', 'private, no-store');
            }

            return $response;
        });
    })->create();
