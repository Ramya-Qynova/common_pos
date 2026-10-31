<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * section 10: "Branch comes from the user's selected branch validated
 * against user_branch_access." Expects an `X-Branch-Id` header (the
 * client's currently-selected branch) and records it on TenantContext for
 * the rest of the request.
 */
class EnsureBranchAccess
{
    public function handle(Request $request, Closure $next)
    {
        $branchId = $request->header('X-Branch-Id');
        if (! $branchId) {
            return $next($request);
        }

        $user = $request->user('tenant');
        if (! $user || ! $user->hasBranchAccess((int) $branchId)) {
            return ApiResponse::error('FORBIDDEN', 'You do not have access to this branch.', 403);
        }

        app(TenantContext::class)->setBranch((int) $branchId);

        return $next($request);
    }
}
