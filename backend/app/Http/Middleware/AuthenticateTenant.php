<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\TenantAuthService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Deliberately NOT built on Auth::viaRequest(): Laravel's RequestGuard
 * caches the first resolved user for the lifetime of the guard instance
 * (Illuminate\Auth\RequestGuard::user()), and AuthManager caches guard
 * instances by name - so within one long-lived process (a persistent
 * worker, or several sequential calls against one Application instance in
 * a test) a token revoked by /auth/logout would keep authenticating
 * against the stale cached user. This middleware re-validates the token
 * against device_sessions on EVERY request and sets the request's user
 * resolver directly, so a revoked/expired token is rejected immediately
 * and always (see docs/DECISIONS.md D-0009).
 */
class AuthenticateTenant
{
    public function __construct(private TenantAuthService $auth) {}

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        $session = $token ? $this->auth->resolveAccessToken($token) : null;

        if (! $session) {
            return ApiResponse::error('UNAUTHENTICATED', 'Unauthenticated.', 401);
        }

        $user = User::withoutTenantScope()->find($session->user_id);
        if (! $user) {
            return ApiResponse::error('UNAUTHENTICATED', 'Unauthenticated.', 401);
        }

        app(TenantContext::class)->setTenant($session->tenant_id, $user->id);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
