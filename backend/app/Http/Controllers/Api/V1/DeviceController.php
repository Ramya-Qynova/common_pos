<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Sync\DeviceService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct(private DeviceService $devices) {}

    public function index(Request $request)
    {
        $devices = $this->devices->listFor(app(TenantContext::class)->tenantId());

        return ApiResponse::success($devices->map(fn (Device $d) => [
            'id' => $d->id, 'uuid' => $d->uuid, 'name' => $d->name, 'platform' => $d->platform,
            'is_revoked' => $d->is_revoked, 'last_seen_at' => $d->last_seen_at,
        ])->all());
    }

    public function revoke(Request $request, int $device)
    {
        $user = $request->user('tenant');
        $revoked = $this->devices->revoke(app(TenantContext::class)->tenantId(), $device, $user->id);

        return ApiResponse::success(['id' => $revoked->id, 'is_revoked' => $revoked->is_revoked]);
    }
}
