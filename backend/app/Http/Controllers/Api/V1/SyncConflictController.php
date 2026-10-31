<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SyncConflict;
use App\Services\Sync\SyncConflictService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class SyncConflictController extends Controller
{
    public function __construct(private SyncConflictService $conflicts) {}

    public function index(Request $request)
    {
        $conflicts = $this->conflicts->listOpen(app(TenantContext::class)->tenantId());

        return ApiResponse::success($conflicts->map(fn (SyncConflict $c) => [
            'id' => $c->id, 'sync_operation_id' => $c->sync_operation_id, 'type' => $c->type, 'details' => $c->details,
        ])->all());
    }

    public function resolve(Request $request, int $conflict)
    {
        $user = $request->user('tenant');
        $resolved = $this->conflicts->resolve(app(TenantContext::class)->tenantId(), $conflict, $user->id);

        return ApiResponse::success(['id' => $resolved->id, 'status' => $resolved->status]);
    }
}
