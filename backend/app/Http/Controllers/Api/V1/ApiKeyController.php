<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\Integrations\ApiKeyService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ApiKeyController extends Controller
{
    public function __construct(private ApiKeyService $apiKeys) {}

    public function index()
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $keys = ApiKey::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get();

        return ApiResponse::success($keys);
    }

    public function store(Request $request)
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
            'expires_at' => 'nullable|date',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $permissions = $data['permissions'] ?? ['*'];
        $result = $this->apiKeys->generate($tenantId, $data['name'], $permissions, $data['expires_at'] ?? null);

        return ApiResponse::success([
            'api_key' => $result['api_key'],
            'plain_key' => $result['plain_key'],
        ], [], 201);
    }

    public function destroy(int $id)
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $this->apiKeys->revoke($tenantId, $id);

        return ApiResponse::success(['revoked' => true]);
    }
}
