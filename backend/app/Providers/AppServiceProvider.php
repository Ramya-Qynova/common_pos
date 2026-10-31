<?php

namespace App\Providers;

use App\Models\Platform\PlatformSession;
use App\Models\Platform\PlatformUser;
use App\Services\Auth\TokenPair;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tenant guard authentication is NOT done via Auth::viaRequest - see
        // App\Http\Middleware\AuthenticateTenant for why (RequestGuard's
        // per-instance user cache would let a revoked token keep working
        // within one long-lived process). That middleware is the real
        // tenant authentication path.

        // Platform guard: structurally separate from the tenant path above
        // (different table, different middleware) - no code path shared
        // with tenant authentication, and it never touches TenantContext.
        // Not yet exercised by a revocation-sensitive flow (no platform
        // login endpoint built this phase - see docs/PROGRESS.md), so it
        // keeps Auth::viaRequest for now; apply the same AuthenticateTenant
        // pattern here if/when a platform session can be revoked mid-use.
        Auth::viaRequest('platform-token', function (Request $request) {
            $token = $request->bearerToken();
            if (! $token) {
                return null;
            }

            $session = PlatformSession::where('access_token_hash', TokenPair::hash($token))->first();
            if (! $session || $session->revoked_at !== null || $session->access_expires_at->isPast()) {
                return null;
            }

            return PlatformUser::find($session->platform_user_id);
        });
    }
}
