<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SyncOperation;
use App\Services\Sync\SyncPullService;
use App\Services\Sync\SyncPushService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SyncController extends Controller
{
    public function __construct(private SyncPushService $push, private SyncPullService $pull) {}

    public function push(Request $request)
    {
        $data = Validator::make($request->all(), [
            'operations' => 'required|array|min:1',
            'operations.*.operation_uuid' => 'required|string',
            'operations.*.type' => 'required|string',
            'operations.*.device_id' => 'required|string',
            'operations.*.user_id' => 'required|integer',
            'operations.*.client_created_at' => 'required|date',
            'operations.*.payload' => 'required|array',
            'operations.*.hash' => 'nullable|string',
        ])->validate();

        $results = $this->push->push(app(TenantContext::class)->tenantId(), $data['operations']);

        return ApiResponse::success(['results' => $results]);
    }

    public function pull(Request $request)
    {
        $data = Validator::make($request->all(), [
            'since' => 'nullable|integer',
            'price_list_id' => 'nullable|integer',
        ])->validate();

        $result = $this->pull->pull(
            app(TenantContext::class)->tenantId(), $data['price_list_id'] ?? null, $data['since'] ?? null,
        );

        return ApiResponse::success($result);
    }

    /**
     * section 13.2: "rejected items stay visible until resolved." Lets a
     * manager actually see what's sitting in each status, not just trust
     * that it's somewhere in the database.
     */
    public function operations(Request $request)
    {
        $data = Validator::make($request->all(), [
            'status' => 'nullable|in:accepted,rejected,held',
        ])->validate();

        $operations = SyncOperation::withoutTenantScope()
            ->where('tenant_id', app(TenantContext::class)->tenantId())
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')->get();

        return ApiResponse::success($operations->map(fn (SyncOperation $o) => [
            'uuid' => $o->uuid, 'type' => $o->type, 'status' => $o->status, 'reject_reason' => $o->reject_reason,
            'client_created_at' => $o->client_created_at, 'received_at' => $o->received_at,
        ])->all());
    }
}
