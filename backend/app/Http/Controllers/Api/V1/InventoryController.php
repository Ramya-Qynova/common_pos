<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CostLayer;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InventoryController extends Controller
{
    public function balances(Request $request)
    {
        $data = Validator::make($request->all(), [
            'warehouse_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
            'low_stock' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $perPage = $data['per_page'] ?? 25;

        $query = StockBalance::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with(['product:id,sku,name,base_unit_id', 'product.baseUnit:id,code,name', 'warehouse:id,name']);

        if (! empty($data['warehouse_id'])) {
            $query->where('warehouse_id', $data['warehouse_id']);
        }

        if (! empty($data['search'])) {
            $term = $data['search'];
            $query->whereHas('product', function ($pq) use ($term) {
                $pq->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%");
            });
        }

        if (! empty($data['low_stock'])) {
            $query->where('quantity', '<=', 5);
        }

        $paginator = $query->orderBy('warehouse_id')->orderBy('product_id')->paginate($perPage, ['*'], 'page', $data['page'] ?? 1);

        $items = collect($paginator->items())->map(function ($sb) {
            $product = $sb->product;
            $warehouse = $sb->warehouse;
            $unit = $product instanceof Product ? $product->baseUnit : null;

            return [
                'id' => $sb->id,
                'warehouse_id' => $sb->warehouse_id,
                'warehouse_name' => $warehouse instanceof Warehouse ? $warehouse->name : 'Default Warehouse',
                'product_id' => $sb->product_id,
                'product_name' => $product instanceof Product ? $product->name : 'Product',
                'sku' => $product instanceof Product ? $product->sku : '',
                'unit' => $unit instanceof Unit ? $unit->code : 'pcs',
                'quantity' => bcadd((string) $sb->quantity, '0', 4),
                'average_cost' => bcadd((string) $sb->average_cost, '0', 4),
                'total_value' => bcmul((string) $sb->quantity, (string) $sb->average_cost, 4),
                'is_low_stock' => bccomp((string) $sb->quantity, '5.0000', 4) <= 0,
            ];
        });

        return ApiResponse::success($items->all(), [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    public function costLayers(Request $request)
    {
        $data = Validator::make($request->all(), [
            'warehouse_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();

        $query = CostLayer::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('quantity_remaining', '>', 0)
            ->with(['product:id,sku,name', 'warehouse:id,name'])
            ->orderBy('received_at', 'asc');

        if (! empty($data['warehouse_id'])) {
            $query->where('warehouse_id', $data['warehouse_id']);
        }

        if (! empty($data['product_id'])) {
            $query->where('product_id', $data['product_id']);
        }

        $layers = $query->get()->map(fn (CostLayer $cl) => [
            'id' => $cl->id,
            'warehouse_id' => $cl->warehouse_id,
            'warehouse_name' => $cl->warehouse?->name,
            'product_id' => $cl->product_id,
            'product_name' => $cl->product?->name,
            'sku' => $cl->product?->sku,
            'quantity_remaining' => (string) $cl->quantity_remaining,
            'unit_cost' => (string) $cl->unit_cost,
            'total_value' => bcmul((string) $cl->quantity_remaining, (string) $cl->unit_cost, 4),
            'received_at' => $cl->received_at?->toISOString() ?? (string) $cl->received_at,
        ]);

        return ApiResponse::success($layers->all());
    }
}
