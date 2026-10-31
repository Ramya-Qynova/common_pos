<?php

namespace App\Http\Middleware;

use App\Models\TenantModule;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * section 4: "A disabled module has no menu entry and its API returns
 * MODULE_DISABLED." Usage: ->middleware('module:pos')
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $moduleCode)
    {
        $tenantId = app(TenantContext::class)->tenantId();

        $enabled = TenantModule::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('module_code', $moduleCode)
            ->where('is_enabled', true)
            ->exists();

        if (! $enabled) {
            return ApiResponse::error('MODULE_DISABLED', "The {$moduleCode} module is not enabled for this account.", 403);
        }

        return $next($request);
    }
}
