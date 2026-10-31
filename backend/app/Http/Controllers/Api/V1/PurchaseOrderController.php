<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Purchasing\PurchaseOrderService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $purchaseOrders) {}

    public function store(Request $request)
    {
        $data = Validator::make($request->all(), [
            'supplier_id' => 'required|integer',
            'warehouse_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.unit_id' => 'required|integer',
            'items.*.quantity' => 'required|string',
            'items.*.unit_cost' => 'required|string',
        ])->validate();

        $user = $request->user('tenant');
        $po = $this->purchaseOrders->create(app(TenantContext::class)->tenantId(), $data, $user->id);

        return ApiResponse::success(['id' => $po->id, 'uuid' => $po->uuid, 'status' => $po->status], [], 201);
    }

    public function markOrdered(Request $request, int $purchaseOrder)
    {
        $user = $request->user('tenant');
        $po = $this->purchaseOrders->markOrdered(app(TenantContext::class)->tenantId(), $purchaseOrder, $user->id);

        return ApiResponse::success(['id' => $po->id, 'status' => $po->status]);
    }
}
