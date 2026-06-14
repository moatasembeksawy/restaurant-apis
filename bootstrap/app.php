<?php

declare(strict_types=1);

use App\Modules\Tenant\Subscription\Exceptions\PlanLimitExceededException;
use App\Shared\Support\Http\Middleware\EnsurePlanFeature;
use App\Shared\Support\Http\Middleware\EnsurePlatformAdmin;
use App\Shared\Support\Http\Middleware\TenantMiddleware;
use App\Shared\Support\Http\Middleware\TenantRateLimitMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => TenantMiddleware::class,
            'feature' => EnsurePlanFeature::class,
            'tenant.rate_limit' => TenantRateLimitMiddleware::class,
            'platform.admin' => EnsurePlatformAdmin::class,
        ]);

        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always return JSON for API routes
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*'),
        );

        // 402 — Plan limit exceeded
        $exceptions->render(function (PlanLimitExceededException $e, Request $request): mixed {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'errors' => [[
                    'message' => $e->getMessage(),
                    'code' => 'PLAN_LIMIT_EXCEEDED',
                    'resource' => $e->resource,
                    'limit' => $e->limit,
                    'upgrade_url' => url('/api/v1/subscription/upgrade'),
                ]],
            ], 402);
        });

        // 401 — Authentication failed
        $exceptions->render(function (AuthenticationException $e, Request $request): mixed {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'errors' => [['message' => 'Unauthenticated.', 'code' => 'UNAUTHENTICATED']],
            ], 401);
        });

        // 422 — Validation errors in consistent format
        $exceptions->render(function (ValidationException $e, Request $request): mixed {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'errors' => collect($e->errors())
                    ->map(fn ($messages, $field) => [
                        'message' => $messages[0],
                        'code' => 'VALIDATION_ERROR',
                        'field' => $field,
                    ])
                    ->values()
                    ->all(),
            ], 422);
        });

        // 404 — Model not found
        $exceptions->render(function (NotFoundHttpException $e, Request $request): mixed {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'errors' => [['message' => 'Resource not found.', 'code' => 'NOT_FOUND']],
            ], 404);
        });
    })->create();
