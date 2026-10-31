<?php

use App\Exceptions\AccountLockedException;
use App\Exceptions\ApiException;
use App\Exceptions\AuthenticationFailedException;
use App\Http\Middleware\AuthenticateTenant;
use App\Http\Middleware\EnsureBranchAccess;
use App\Http\Middleware\EnsureIdempotent;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureTenantActive;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
            'tenant.auth' => AuthenticateTenant::class,
            'tenant.active' => EnsureTenantActive::class,
            'module' => EnsureModuleEnabled::class,
            'permission' => EnsurePermission::class,
            'branch' => EnsureBranchAccess::class,
            'idempotent' => EnsureIdempotent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Standard error envelope (section 5) for every API exception.
        $exceptions->render(function (ApiException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->status, $e->fields);
        });

        $exceptions->render(function (AuthenticationFailedException|AuthenticationException $e) {
            return ApiResponse::error('UNAUTHENTICATED', 'Invalid credentials.', 401);
        });

        $exceptions->render(function (AccountLockedException $e) {
            return ApiResponse::error('FORBIDDEN', 'This account is temporarily locked. Try again later.', 403);
        });

        $exceptions->render(function (ValidationException $e) {
            return ApiResponse::error('VALIDATION_FAILED', 'The given data was invalid.', 422, $e->errors());
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', 404);
        });
    })->create();
