<?php

namespace App\Services\Catalogue;

use App\Models\PriceListItem;
use App\Models\ProductPrice;
use App\Services\Audit\AuditLogger;
use App\Services\Sync\ChangeLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * section 7 "price lists + history": a price change is never an UPDATE on
 * the current row - it closes the previous price_list_items row
 * (effective_to) and inserts a new one, then refreshes the product_prices
 * lookup cache. Full history stays queryable in price_list_items;
 * product_prices only ever holds the current value.
 */
class PriceListService
{
    public function setPrice(int $tenantId, int $priceListId, int $productId, ?int $variantId, int $unitId, string $price, ?Carbon $effectiveFrom = null): PriceListItem
    {
        $effectiveFrom ??= now();

        return DB::transaction(function () use ($tenantId, $priceListId, $productId, $variantId, $unitId, $price, $effectiveFrom) {
            PriceListItem::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('price_list_id', $priceListId)
                ->where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->whereNull('effective_to')
                ->update(['effective_to' => $effectiveFrom]);

            $item = PriceListItem::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'price_list_id' => $priceListId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'unit_id' => $unitId,
                'price' => $price,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
            ]);

            $current = ProductPrice::withoutTenantScope()->updateOrCreate(
                [
                    'tenant_id' => $tenantId, 'product_id' => $productId, 'variant_key' => $variantId ?? 0,
                    'price_list_id' => $priceListId, 'unit_id' => $unitId,
                ],
                ['variant_id' => $variantId, 'price' => $price],
            );

            ChangeLogger::log($tenantId, 'ProductPrice', $current->id, 'updated', $current->toArray());

            return $item;
        });
    }

    /**
     * section 12 Phase 6 "daily rate update" - a generic bulk price change
     * usable by any tenant/template (a vegetable shop happens to use this
     * every morning; nothing here is specific to that). One transaction,
     * one audit entry for the whole batch, each line going through the
     * exact same history-preserving setPrice() a single change would.
     *
     * @param  array<int,array{product_id:int,variant_id?:int|null,unit_id:int,price:string}>  $updates
     * @return array<int,PriceListItem>
     */
    public function bulkSetPrices(int $tenantId, int $priceListId, array $updates, int $userId): array
    {
        return DB::transaction(function () use ($tenantId, $priceListId, $updates, $userId) {
            $effectiveFrom = now();
            $items = array_map(
                fn (array $u) => $this->setPrice($tenantId, $priceListId, $u['product_id'], $u['variant_id'] ?? null, $u['unit_id'], $u['price'], $effectiveFrom),
                $updates,
            );

            AuditLogger::log($tenantId, $userId, 'price_list.bulk_updated', 'PriceList', $priceListId, null, [
                'item_count' => count($items), 'product_ids' => array_column($updates, 'product_id'),
            ]);

            return $items;
        });
    }

    public function currentPrice(int $tenantId, int $priceListId, int $productId, ?int $variantId, int $unitId): ?string
    {
        return ProductPrice::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->where('variant_key', $variantId ?? 0)
            ->where('price_list_id', $priceListId)
            ->where('unit_id', $unitId)
            ->value('price');
    }
}
