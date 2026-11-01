<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Barcode;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Services\Catalogue\ProductService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * section 10 "Catalogue" endpoint group.
 */
class ProductController extends Controller
{
    public function index(Request $request)
    {
        $data = Validator::make($request->all(), [
            'search' => 'nullable|string|max:100',
            'category_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'price_list_id' => 'nullable|integer',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $perPage = $data['per_page'] ?? 25;

        $query = Product::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with(['barcodes', 'category:id,name', 'baseUnit:id,code,name']);

        if (! empty($data['search'])) {
            $term = $data['search'];
            $query->where(function ($q) use ($term) {
                $q->where('sku', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhereHas('barcodes', fn ($bq) => $bq->where('barcode', 'like', "%{$term}%"));
            });
        }

        if (! empty($data['category_id'])) {
            $query->where('category_id', $data['category_id']);
        }

        $paginator = $query->orderBy('name')->paginate($perPage, ['*'], 'page', $data['page'] ?? 1);

        $productIds = collect($paginator->items())->pluck('id');

        $prices = isset($data['price_list_id'])
            ? ProductPrice::withoutTenantScope()->where('tenant_id', $tenantId)->where('price_list_id', $data['price_list_id'])->whereIn('product_id', $productIds)->get()->groupBy('product_id')
            : ProductPrice::withoutTenantScope()->where('tenant_id', $tenantId)->whereIn('product_id', $productIds)->get()->groupBy('product_id');

        $balances = isset($data['warehouse_id'])
            ? StockBalance::withoutTenantScope()->where('tenant_id', $tenantId)->where('warehouse_id', $data['warehouse_id'])->whereIn('product_id', $productIds)->get()->groupBy('product_id')
            : StockBalance::withoutTenantScope()->where('tenant_id', $tenantId)->whereIn('product_id', $productIds)->get()->groupBy('product_id');

        $items = collect($paginator->items())->map(function (Product $product) use ($prices, $balances) {
            $productPrices = $prices->get($product->id, collect());
            $productBalances = $balances->get($product->id, collect());
            $unit = $product->baseUnit;
            $category = $product->category;

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'base_unit_id' => $product->base_unit_id,
                'unit' => $unit instanceof Unit ? $unit->code : 'pcs',
                'category' => $category instanceof Category ? $category->name : null,
                'barcodes' => $product->barcodes->map(fn (Barcode $b) => ['barcode' => $b->barcode, 'type' => $b->type, 'unit_id' => $b->unit_id])->all(),
                'prices' => $productPrices->map(fn (ProductPrice $p) => ['unit_id' => $p->unit_id, 'variant_key' => $p->variant_key, 'price' => $p->price])->all(),
                'default_price' => $productPrices->first()?->price ?? '0.0000',
                'stock_quantity' => (string) ($productBalances->sum('quantity') ?: '0.0000'),
            ];
        });

        return ApiResponse::success($items->all(), [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    public function store(Request $request, ProductService $service)
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:50',
            'base_unit_id' => 'required|integer',
            'category_id' => 'nullable|integer',
            'barcode' => 'nullable|string|max:100',
            'price' => 'nullable|string',
            'price_list_id' => 'nullable|integer',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $user = $request->user('tenant');

        $product = $service->create($tenantId, [
            'name' => $data['name'],
            'sku' => $data['sku'],
            'base_unit_id' => $data['base_unit_id'],
            'category_id' => $data['category_id'] ?? null,
        ], $user?->id);

        if (! empty($data['barcode'])) {
            Barcode::create([
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'barcode' => $data['barcode'],
                'type' => 'code128',
                'unit_id' => $data['base_unit_id'],
            ]);
        }

        if (isset($data['price'])) {
            $priceListId = $data['price_list_id'] ?? PriceList::where('tenant_id', $tenantId)->value('id');
            if ($priceListId) {
                ProductPrice::create([
                    'tenant_id' => $tenantId,
                    'price_list_id' => $priceListId,
                    'product_id' => $product->id,
                    'unit_id' => $data['base_unit_id'],
                    'variant_key' => 0,
                    'price' => $data['price'],
                ]);
            }
        }

        return ApiResponse::success([
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
        ], [], 201);
    }
}
