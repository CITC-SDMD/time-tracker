<?php

use App\Http\Middleware\CheckAgentVersion;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAgentOrganization;
use App\Http\Middleware\EnsureSelfOrVisible;
use App\Http\Middleware\EnsureSuperadmin;
use App\Http\Middleware\LimitAgentToken;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequirePlatformPermission;
use App\Http\Middleware\ResetOrganizationContext;
use App\Http\Middleware\SetOrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The dashboard signs in with a session cookie (Sanctum SPA); the agent keeps its token.
        $middleware->statefulApi();

        // There is no login page on this server (the dashboard owns it), so a signed-out request is
        // answered 401 even when it does not ask for JSON, instead of failing on a missing "login" route.
        $middleware->redirectGuestsTo(fn () => null);

        // every request starts outside any organization; `org` puts the signed-in person inside theirs
        $middleware->prepend(ResetOrganizationContext::class);

        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'agent-token' => LimitAgentToken::class,
            'agent-organization' => EnsureAgentOrganization::class,
            'check-agent-version' => CheckAgentVersion::class,
            'org' => SetOrganizationContext::class,
            'permission' => RequirePermission::class,
            'platform' => RequirePlatformPermission::class,
            'self-or-visible' => EnsureSelfOrVisible::class,
            'superadmin' => EnsureSuperadmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Reshape every API error into { "error": { "code", "message" } } (§10) —
        // covers both Laravel's own exceptions (auth, validation, 404, ...) and the
        // ones our middleware/controllers throw deliberately.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            [$status, $code, $message] = match (true) {
                $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Not logged in.'],
                $e instanceof AuthorizationException => [403, 'FORBIDDEN', $e->getMessage() ?: 'Not allowed.'],
                $e instanceof ValidationException => [422, 'VALIDATION_FAILED', $e->getMessage()],
                $e instanceof ModelNotFoundException => [404, 'NOT_FOUND', 'Not found.'],
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(),
                    Response::$statusTexts[$e->getStatusCode()] ?? 'ERROR',
                    $e->getMessage() ?: (Response::$statusTexts[$e->getStatusCode()] ?? 'Error.'),
                ],
                default => [500, 'SERVER_ERROR', app()->hasDebugModeEnabled() ? $e->getMessage() : 'Something went wrong.'],
            };

            $body = ['error' => ['code' => str_replace(' ', '_', strtoupper((string) $code)), 'message' => $message]];
            if ($e instanceof ValidationException) {
                $body['error']['fields'] = $e->errors();
            }

            return response()->json($body, $status);
        });
    })->create();
