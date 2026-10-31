<?php

namespace App\Services\Inventory;

use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 8 "Stock operations": opening stock/adjustment/damage/wastage
 * need `inventory.adjust` plus a mandatory reason (enforced by the
 * `permission` middleware + the required `reason` here, not re-checked
 * twice) - posts through StockService so it gets the exact same locked,
 * ledger-then-balance pattern a sale or purchase would.
 *
 * Rule-based approval escalation ("approval if a rule matches") is not
 * wired up yet - the rule engine has no evaluator until a later phase
 * (docs/DECISIONS.md / docs/PROGRESS.md) - so every adjustment here posts
 * immediately; `approved_by` stays available for when that lands.
 */
class StockAdjustmentService
{
    public function __construct(private StockService $stock) {}

    /**
     * @param  array<int,array{product_id:int,variant_id?:int|null,batch_id?:int|null,direction:'in'|'out',quantity:string,unit_cost?:string|null}>  $items
     */
    public function post(int $tenantId, int $warehouseId, string $type, string $reason, array $items, int $createdByUserId): StockAdjustment
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required for every stock adjustment (section 8).');
        }

        return DB::transaction(function () use ($tenantId, $warehouseId, $type, $reason, $items, $createdByUserId) {
            $adjustment = StockAdjustment::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'uuid' => (string) Str::uuid(),
                'warehouse_id' => $warehouseId,
                'type' => $type,
                'reason' => $reason,
                'created_by' => $createdByUserId,
                'posted_at' => now(),
            ]);

            $movements = [];
            foreach ($items as $item) {
                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'batch_id' => $item['batch_id'] ?? null,
                    'direction' => $item['direction'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'] ?? null,
                ]);

                $movements[] = [
                    'warehouse_id' => $warehouseId,
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'batch_id' => $item['batch_id'] ?? null,
                    'direction' => $item['direction'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'] ?? null,
                    'source_type' => $type,
                    'source_id' => $adjustment->id,
                    'reason' => $reason,
                ];
            }

            $this->stock->recordMovements($tenantId, $movements, ['user_id' => $createdByUserId]);

            AuditLogger::log($tenantId, $createdByUserId, 'stock_adjustment.posted', 'StockAdjustment', $adjustment->id, null, [
                'type' => $type, 'reason' => $reason, 'item_count' => count($items),
            ]);

            return $adjustment;
        });
    }
}
