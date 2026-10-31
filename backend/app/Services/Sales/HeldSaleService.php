<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\HeldSale;
use App\Models\HeldSaleItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 7: "Held bills live in held_sales, never in sales." Resuming
 * does NOT freeze a price - it hands the stored line selections back to
 * the caller, which feeds them into SaleService::create() exactly like a
 * fresh sale, so G1 (server always recomputes prices) holds even for a
 * bill held an hour ago with since-changed prices.
 */
class HeldSaleService
{
    public function hold(int $tenantId, array $input, int $userId): HeldSale
    {
        return DB::transaction(function () use ($tenantId, $input, $userId) {
            $held = HeldSale::withoutTenantScope()->create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(),
                'branch_id' => $input['branch_id'], 'warehouse_id' => $input['warehouse_id'],
                'register_id' => $input['register_id'], 'customer_id' => $input['customer_id'] ?? null,
                'created_by' => $userId, 'status' => 'held', 'held_at' => now(),
            ]);

            foreach ($input['lines'] as $line) {
                HeldSaleItem::create([
                    'held_sale_id' => $held->id, 'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'] ?? null, 'unit_id' => $line['unit_id'],
                    'quantity' => $line['quantity'],
                ]);
            }

            return $held->load('items');
        });
    }

    /**
     * Marks the held bill resumed and returns its stored line selections
     * (product/variant/unit/quantity only - no price) for the caller to
     * pass straight into SaleService::create().
     */
    public function resume(int $tenantId, int $heldSaleId): array
    {
        return DB::transaction(function () use ($tenantId, $heldSaleId) {
            $held = HeldSale::withoutTenantScope()->where('tenant_id', $tenantId)->where('id', $heldSaleId)->lockForUpdate()->first();

            if (! $held || $held->status !== 'held') {
                throw new ApiException('CONFLICT', 'This held bill is not available to resume.', 409);
            }

            $held->status = 'resumed';
            $held->save();

            return $held->items->map(fn ($item) => [
                'product_id' => $item->product_id, 'variant_id' => $item->variant_id,
                'unit_id' => $item->unit_id, 'quantity' => $item->quantity,
            ])->all();
        });
    }
}
