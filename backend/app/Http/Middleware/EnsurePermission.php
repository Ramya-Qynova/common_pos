<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/**
 * G11: authorization is by permission CODE only, never a role name.
 * Usage: ->middleware('permission:sales.create')
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissionCode)
    {
        $user = $request->user('tenant');

        if (! $user || ! $user->hasPermission($permissionCode)) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to perform this action.', 403);
        }

        return $next($request);
    }
}
