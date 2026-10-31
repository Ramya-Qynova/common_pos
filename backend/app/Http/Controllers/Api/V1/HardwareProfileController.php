<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Hardware\HardwareProfileService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HardwareProfileController extends Controller
{
    public function __construct(private HardwareProfileService $profiles) {}

    public function index(Request $request)
    {
        $data = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer',
            'register_id' => 'nullable|integer',
        ])->validate();

        $profiles = $this->profiles->listFor(app(TenantContext::class)->tenantId(), $data['branch_id'] ?? null, $data['register_id'] ?? null);

        return ApiResponse::success($profiles->map(fn ($p) => [
            'id' => $p->id, 'code' => $p->code, 'type' => $p->type, 'config' => $p->config,
            'branch_id' => $p->branch_id, 'register_id' => $p->register_id,
        ])->all());
    }

    public function store(Request $request)
    {
        $data = Validator::make($request->all(), [
            'code' => 'required|string',
            'type' => 'required|string',
            'config' => 'required|array',
            'branch_id' => 'nullable|integer',
            'register_id' => 'nullable|integer',
        ])->validate();

        $user = $request->user('tenant');
        $profile = $this->profiles->upsert(
            app(TenantContext::class)->tenantId(), $data['code'], $data['type'], $data['config'],
            $data['branch_id'] ?? null, $data['register_id'] ?? null, $user->id,
        );

        return ApiResponse::success(['id' => $profile->id, 'code' => $profile->code, 'type' => $profile->type], [], 201);
    }
}
