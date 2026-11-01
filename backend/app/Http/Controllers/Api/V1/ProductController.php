<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Barcode;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\StockBalance;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * section 10 "Catalogue" endpoint group. The POS screen's product search -
 * read-only, so gated by `pos.sales.create` (the same permission a cashier
 * already needs to bill at all) rather than `catalog.products.manage`
 * (reserved for actually editing a product). Always server-side paginated
 * (section 11 "list columns ... always server-side paginated") - never a
 * "load everything and filter in the browser" endpoint.
 */
class ProductController extends Controller
{
    public function index(Request $request)
    {
        $data = Validator::make($request->all(), [
            'search' => 'nullable|string|max:100',
            'warehouse_id' => 'nullable|integer',
            'price_list_id' => 'nullable|integer',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $perPage = $data['per_page'] ?? 25;

        $query = Product::withoutTenantScope()->where('tenant_id', $tenantId)->where('is_active', true)->with('barcodes');

        if (! empty($data['search'])) {
            $term = $data['search'];
            $query->where(function ($q) use ($term) {
                $q->where('sku', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%");
            });
        }

        $paginator = $query->orderBy('name')->paginate($perPage, ['*'], 'page', $data['page'] ?? 1);

        $productIds = collect($paginator->items())->pluck('id');

        $prices = isset($data['price_list_id'])
            ? ProductPrice::withoutTenantScope()->where('tenant_id', $tenantId)->where('price_list_id', $data['price_list_id'])->whereIn('product_id', $productIds)->get()->groupBy('product_id')
            : collect();

        $balances = isset($data['warehouse_id'])
            ? StockBalance::withoutTenantScope()->where('tenant_id', $tenantId)->where('warehouse_id', $data['warehouse_id'])->whereIn('product_id', $productIds)->get()->groupBy('product_id')
            : collect();

        $items = collect($paginator->items())->map(function (Product $product) use ($prices, $balances) {
            $productPrices = $prices->get($product->id, collect());
            $productBalances = $balances->get($product->id, collect());

            return [
                'id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'base_unit_id' => $product->base_unit_id,
                'barcodes' => $product->barcodes->map(fn (Barcode $b) => ['barcode' => $b->barcode, 'type' => $b->type, 'unit_id' => $b->unit_id])->all(),
                'prices' => $productPrices->map(fn (ProductPrice $p) => ['unit_id' => $p->unit_id, 'variant_key' => $p->variant_key, 'price' => $p->price])->all(),
                'stock_quantity' => $productBalances->where('variant_key', 0)->where('batch_key', 0)->first()?->quantity,
            ];
        });

        return ApiResponse::success($items->all(), [
            'page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
            'total' => $paginator->total(), 'last_page' => $paginator->lastPage(),
        ]);
    }
}
