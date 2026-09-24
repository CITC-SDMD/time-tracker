<?php

use App\Http\Middleware\CheckAgentVersion;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureManager;
use App\Http\Middleware\EnsureOic;
use App\Http\Middleware\EnsureSelfOrVisible;
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
        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'check-agent-version' => CheckAgentVersion::class,
            'manager' => EnsureManager::class,
            'oic' => EnsureOic::class,
            'self-or-visible' => EnsureSelfOrVisible::class,
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
