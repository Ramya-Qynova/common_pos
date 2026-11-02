<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Integrations\WebhookService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WebhookController extends Controller
{
    public function __construct(private WebhookService $webhooks) {}

    public function index()
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $items = Webhook::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->withCount('deliveries')
            ->orderBy('created_at', 'desc')
            ->get();

        return ApiResponse::success($items);
    }

    public function store(Request $request)
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'url' => 'required|url|max:255',
            'secret' => 'nullable|string|max:64',
            'events' => 'required|array',
            'events.*' => 'string',
            'is_active' => 'nullable|boolean',
            'headers' => 'nullable|array',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $webhook = $this->webhooks->register($tenantId, $data);

        return ApiResponse::success($webhook, [], 201);
    }

    public function show(int $id)
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $webhook = Webhook::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return ApiResponse::success($webhook);
    }

    public function update(Request $request, int $id)
    {
        $data = Validator::make($request->all(), [
            'name' => 'nullable|string|max:100',
            'url' => 'nullable|url|max:255',
            'secret' => 'nullable|string|max:64',
            'events' => 'nullable|array',
            'events.*' => 'string',
            'is_active' => 'nullable|boolean',
            'headers' => 'nullable|array',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $webhook = $this->webhooks->update($tenantId, $id, $data);

        return ApiResponse::success($webhook);
    }

    public function destroy(int $id)
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $this->webhooks->delete($tenantId, $id);

        return ApiResponse::success(['deleted' => true]);
    }

    public function test(int $id)
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $delivery = $this->webhooks->testPing($tenantId, $id);

        return ApiResponse::success($delivery);
    }

    public function deliveries(int $id)
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $deliveries = WebhookDelivery::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('webhook_id', $id)
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        return ApiResponse::success($deliveries);
    }

    public function retryDelivery(int $deliveryId)
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $delivery = $this->webhooks->retry($tenantId, $deliveryId);

        return ApiResponse::success($delivery);
    }
}
