<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Request pipeline step "tenant status & subscription check" (section 5).
 * Runs after authentication (which populates TenantContext) and before
 * module/permission checks.
 */
class EnsureTenantActive
{
    public function handle(Request $request, Closure $next)
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $tenant = $tenantId ? Tenant::withoutGlobalScopes()->find($tenantId) : null;

        if (! $tenant) {
            return ApiResponse::error('UNAUTHENTICATED', 'Unauthenticated.', 401);
        }

        if ($tenant->status === 'suspended') {
            return ApiResponse::error('TENANT_SUSPENDED', 'This account is suspended.', 403);
        }

        if ($tenant->status === 'closed') {
            return ApiResponse::error('TENANT_SUSPENDED', 'This account is closed.', 403);
        }

        return $next($request);
    }
}
