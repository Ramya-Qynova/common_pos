<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Catalogue\PriceListService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PriceListController extends Controller
{
    public function __construct(private PriceListService $priceLists) {}

    public function bulkUpdate(Request $request, int $priceList)
    {
        $data = Validator::make($request->all(), [
            'updates' => 'required|array|min:1',
            'updates.*.product_id' => 'required|integer',
            'updates.*.variant_id' => 'nullable|integer',
            'updates.*.unit_id' => 'required|integer',
            'updates.*.price' => 'required|string',
        ])->validate();

        $user = $request->user('tenant');
        $items = $this->priceLists->bulkSetPrices(app(TenantContext::class)->tenantId(), $priceList, $data['updates'], $user->id);

        return ApiResponse::success([
            'updated' => array_map(fn ($i) => ['product_id' => $i->product_id, 'unit_id' => $i->unit_id, 'price' => $i->price], $items),
        ], [], 201);
    }
}
