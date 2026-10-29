<?php

use App\Exceptions\ImportPlanChanged;
use App\Exceptions\MediaNotReady;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\RequireGuestSession;
use App\Http\Middleware\RequireMfa;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SerializeMfaChallenge;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SetLocale::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['guest-session' => RequireGuestSession::class, 'active' => EnsureAccountActive::class, 'recent-password' => RequireRecentPassword::class, 'mfa' => RequireMfa::class, 'serialized-mfa' => SerializeMfaChallenge::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (MediaNotReady $error) => response()->json(['message' => $error->getMessage(), 'code' => 'media_not_ready'], 409));
        $exceptions->render(fn (ImportPlanChanged $error) => response()->json(['message' => $error->getMessage(), 'code' => 'import_plan_changed'], 409));
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->respond(function (Response $response): Response {
            if ($response instanceof JsonResponse) {
                $data = $response->getData(true);
                if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
                    $data['message'] = __($data['message']);
                    $response->setData($data);
                }
            }
            $response->headers->set('X-Request-ID', (string) Context::get('request_id'));
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            if (request()->is('api/*')) {
                $response->headers->set('Cache-Control', 'private, no-store');
                $response->headers->set('Content-Language', app()->getLocale() === 'en' ? 'en-US' : 'fa-IR');
                $response->setVary('Accept-Language', false);
            }

            return $response;
        });
    })->create();
