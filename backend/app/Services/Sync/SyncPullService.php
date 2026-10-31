<?php

namespace App\Services\Sync;

use App\Models\Barcode;
use App\Models\ChangeLogEntry;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Database\Eloquent\Collection;

/**
 * section 9 "Snapshot and pull": "the first login downloads a full
 * snapshot for the branch/warehouse. GET /sync/pull?since=version returns
 * authorized changes from change_log in version order." Deliberately
 * scoped to products/prices/customers - the entities the IndexedDB
 * snapshot stores actually need for offline billing (settings/tax/payment
 * methods are already served by GET /tenant/config - see
 * docs/DECISIONS.md).
 */
class SyncPullService
{
    public function pull(int $tenantId, ?int $priceListId, ?int $since): array
    {
        $priceListId ??= PriceList::withoutTenantScope()->where('tenant_id', $tenantId)->where('is_default', true)->value('id');

        if ($since === null || $since <= 0) {
            return $this->fullSnapshot($tenantId, $priceListId);
        }

        return $this->incremental($tenantId, $priceListId, $since);
    }

    private function fullSnapshot(int $tenantId, ?int $priceListId): array
    {
        $products = Product::withoutTenantScope()->where('tenant_id', $tenantId)->where('is_active', true)->with('barcodes')->get();
        $customers = Customer::withoutTenantScope()->where('tenant_id', $tenantId)->where('is_active', true)
            ->get(['id', 'uuid', 'code', 'name', 'phone', 'credit_limit']);

        $version = (int) (ChangeLogEntry::withoutTenantScope()->where('tenant_id', $tenantId)->max('id') ?? 0);

        return [
            'version' => $version, 'full' => true,
            'products' => $this->serializeProducts($tenantId, $priceListId, $products),
            'customers' => $customers,
        ];
    }

    private function incremental(int $tenantId, ?int $priceListId, int $since): array
    {
        $entries = ChangeLogEntry::withoutTenantScope()->where('tenant_id', $tenantId)->where('id', '>', $since)->orderBy('id')->get();

        if ($entries->isEmpty()) {
            return ['version' => $since, 'full' => false, 'products' => [], 'customers' => []];
        }

        $productIds = $entries->where('entity_type', 'Product')->pluck('entity_id');
        $pricedProductIds = ProductPrice::withoutTenantScope()
            ->whereIn('id', $entries->where('entity_type', 'ProductPrice')->pluck('entity_id'))
            ->pluck('product_id');

        $touchedProductIds = $productIds->merge($pricedProductIds)->unique()->values();
        $products = Product::withoutTenantScope()->whereIn('id', $touchedProductIds)->with('barcodes')->get();

        return [
            'version' => (int) $entries->max('id'), 'full' => false,
            'products' => $this->serializeProducts($tenantId, $priceListId, $products),
            'customers' => [],
        ];
    }

    /**
     * @param  Collection<int,Product>  $products
     */
    private function serializeProducts(int $tenantId, ?int $priceListId, Collection $products): array
    {
        return $products->map(function (Product $product) use ($tenantId, $priceListId) {
            $prices = $priceListId
                ? ProductPrice::withoutTenantScope()->where('tenant_id', $tenantId)->where('product_id', $product->id)->where('price_list_id', $priceListId)->get(['unit_id', 'variant_key', 'price'])
                : collect();

            return [
                'id' => $product->id, 'uuid' => $product->uuid, 'sku' => $product->sku, 'name' => $product->name,
                'base_unit_id' => $product->base_unit_id, 'is_active' => $product->is_active,
                'barcodes' => $product->barcodes->map(fn (Barcode $b) => ['barcode' => $b->barcode, 'type' => $b->type, 'unit_id' => $b->unit_id])->all(),
                'prices' => $prices->map(fn (ProductPrice $p) => ['unit_id' => $p->unit_id, 'variant_key' => $p->variant_key, 'price' => $p->price])->all(),
            ];
        })->all();
    }
}
