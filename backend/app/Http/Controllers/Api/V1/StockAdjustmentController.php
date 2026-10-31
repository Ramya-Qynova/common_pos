<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Inventory\StockAdjustmentService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * section 8 "Stock operations": opening stock/adjustment/damage/wastage,
 * all the exact same generic op - `type` and `reason` are free data, never
 * branched on in code (a grocery shop's "wastage" and anyone else's
 * "damage" are the same request shape). No HTTP route existed for
 * StockAdjustmentService before this phase (Phase 2 built it service-only).
 */
class StockAdjustmentController extends Controller
{
    public function __construct(private StockAdjustmentService $adjustments) {}

    public function store(Request $request)
    {
        $data = Validator::make($request->all(), [
            'warehouse_id' => 'required|integer',
            'type' => 'required|string',
            'reason' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.batch_id' => 'nullable|integer',
            'items.*.direction' => 'required|in:in,out',
            'items.*.quantity' => 'required|string',
            'items.*.unit_cost' => 'nullable|string',
        ])->validate();

        $user = $request->user('tenant');
        $adjustment = $this->adjustments->post(
            app(TenantContext::class)->tenantId(), $data['warehouse_id'], $data['type'], $data['reason'], $data['items'], $user->id,
        );

        return ApiResponse::success(['id' => $adjustment->id, 'uuid' => $adjustment->uuid, 'type' => $adjustment->type], [], 201);
    }
}
