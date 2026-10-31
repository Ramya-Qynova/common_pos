<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Sales\SaleReturnService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SaleReturnController extends Controller
{
    public function __construct(private SaleReturnService $returns) {}

    public function store(Request $request, int $sale)
    {
        $data = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.sale_item_id' => 'required|integer',
            'items.*.quantity' => 'required|string',
            'items.*.condition' => 'nullable|in:resellable,damaged',
        ])->validate();

        $user = $request->user('tenant');
        $return = $this->returns->requestReturn(
            app(TenantContext::class)->tenantId(), $sale, $data['items'], $data['reason'] ?? null, $user->id,
        );

        return ApiResponse::success([
            'id' => $return->id, 'uuid' => $return->uuid, 'status' => $return->status,
            'items' => $return->items->map(fn ($i) => ['sale_item_id' => $i->sale_item_id, 'quantity' => $i->quantity, 'condition' => $i->condition]),
        ], [], 201);
    }

    public function inspect(Request $request, int $return)
    {
        $user = $request->user('tenant');
        $saleReturn = $this->returns->inspect(app(TenantContext::class)->tenantId(), $return, $user->id);

        return ApiResponse::success(['id' => $saleReturn->id, 'status' => $saleReturn->status]);
    }

    public function complete(Request $request, int $return)
    {
        $data = Validator::make($request->all(), [
            'refund_method' => 'nullable|in:original,cash,credit',
        ])->validate();

        $user = $request->user('tenant');
        $refund = $this->returns->complete(
            app(TenantContext::class)->tenantId(), $return, $user->id, $data['refund_method'] ?? 'original',
        );

        return ApiResponse::success([
            'id' => $refund->id, 'uuid' => $refund->uuid, 'amount' => $refund->amount,
            'method' => $refund->method, 'status' => $refund->status,
        ], [], 201);
    }
}
