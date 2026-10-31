<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Purchasing\PurchaseReceivingService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PurchaseController extends Controller
{
    public function __construct(private PurchaseReceivingService $receiving) {}

    public function store(Request $request)
    {
        $data = Validator::make($request->all(), [
            'supplier_id' => 'required|integer',
            'warehouse_id' => 'required|integer',
            'purchase_order_id' => 'nullable|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.unit_id' => 'required|integer',
            'items.*.quantity' => 'required|string',
            'items.*.unit_cost' => 'required|string',
            'items.*.tax_amount' => 'nullable|string',
        ])->validate();

        $user = $request->user('tenant');
        $purchase = $this->receiving->receive(
            app(TenantContext::class)->tenantId(), $data['supplier_id'], $data['warehouse_id'],
            $data['items'], $user->id, $data['purchase_order_id'] ?? null,
        );

        return ApiResponse::success([
            'id' => $purchase->id, 'uuid' => $purchase->uuid, 'grand_total' => $purchase->grand_total,
            'items' => $purchase->items->map(fn ($i) => ['product_id' => $i->product_id, 'quantity' => $i->quantity, 'line_total' => $i->line_total]),
        ], [], 201);
    }
}
