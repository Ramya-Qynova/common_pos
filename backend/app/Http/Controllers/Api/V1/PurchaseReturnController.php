<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Purchasing\PurchaseReturnService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PurchaseReturnController extends Controller
{
    public function __construct(private PurchaseReturnService $returns) {}

    public function store(Request $request, int $purchase)
    {
        $data = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.purchase_item_id' => 'required|integer',
            'items.*.quantity' => 'required|string',
        ])->validate();

        $user = $request->user('tenant');
        $return = $this->returns->create(
            app(TenantContext::class)->tenantId(), $purchase, $data['items'], $data['reason'] ?? null, $user->id,
        );

        return ApiResponse::success(['id' => $return->id, 'uuid' => $return->uuid], [], 201);
    }
}
