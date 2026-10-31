<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\StockBalance;
use App\Models\StockLedger;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one stock-operation pattern (docs/MASTER_PROMPT.md section 8 "Stock
 * operations"): validate -> lock balance rows in a consistent order
 * (warehouse, product, variant, batch) -> insert a ledger row (with
 * unit_cost) -> update the balance -> audit, all in one transaction.
 * Multiple movements in one call (e.g. a multi-item sale in a later phase)
 * are sorted into that same order before any row is locked, so two
 * requests touching the same products in opposite order can never deadlock
 * (section 13.1).
 */
class StockService
{
    public function __construct(private SettingsService $settings) {}

    /**
     * @param  array<int,array{warehouse_id:int,product_id:int,variant_id?:int|null,batch_id?:int|null,direction:'in'|'out',quantity:string,unit_cost?:string|null,source_type:string,source_id?:int|null,source_line_id?:int|null,reason?:string|null}>  $movements
     * @return array<int,StockLedger>
     */
    public function recordMovements(int $tenantId, array $movements, array $context = []): array
    {
        usort($movements, function (array $a, array $b) {
            return [$a['warehouse_id'], $a['product_id'], $a['variant_id'] ?? 0, $a['batch_id'] ?? 0]
                <=> [$b['warehouse_id'], $b['product_id'], $b['variant_id'] ?? 0, $b['batch_id'] ?? 0];
        });

        return DB::transaction(function () use ($tenantId, $movements, $context) {
            $entries = [];
            foreach ($movements as $movement) {
                $balance = $this->lockOrCreateBalance(
                    $tenantId,
                    $movement['warehouse_id'],
                    $movement['product_id'],
                    $movement['variant_id'] ?? null,
                    $movement['batch_id'] ?? null,
                );
                $entries[] = $this->applyMovement($balance, $movement, $context);
            }

            return $entries;
        });
    }

    public function recordMovement(int $tenantId, array $movement, array $context = []): StockLedger
    {
        return $this->recordMovements($tenantId, [$movement], $context)[0];
    }

    /**
     * @return Collection<int,StockBalance>
     */
    public function lowStockBalances(int $tenantId, ?int $warehouseId = null)
    {
        return StockBalance::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereNotNull('reorder_point')
            ->whereColumn('quantity', '<=', 'reorder_point')
            ->get();
    }

    private function lockOrCreateBalance(int $tenantId, int $warehouseId, int $productId, ?int $variantId, ?int $batchId): StockBalance
    {
        $variantKey = $variantId ?? 0;
        $batchKey = $batchId ?? 0;

        $query = fn () => StockBalance::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('variant_key', $variantKey)
            ->where('batch_key', $batchKey);

        $balance = $query()->lockForUpdate()->first();
        if ($balance) {
            return $balance;
        }

        try {
            return StockBalance::withoutTenantScope()->create([
                'tenant_id' => $tenantId, 'warehouse_id' => $warehouseId, 'product_id' => $productId,
                'variant_id' => $variantId, 'batch_id' => $batchId,
                'variant_key' => $variantKey, 'batch_key' => $batchKey,
                'quantity' => '0.0000', 'average_cost' => '0.0000',
            ]);
        } catch (QueryException $e) {
            // Lost the first-movement insert race to a concurrent transaction;
            // its row is now locked until it commits - waiting for that lock
            // here is correct serialization, not a bug.
            $balance = $query()->lockForUpdate()->first();
            if (! $balance) {
                throw $e;
            }

            return $balance;
        }
    }

    private function applyMovement(StockBalance $balance, array $movement, array $context): StockLedger
    {
        $tenantId = $balance->tenant_id;
        $direction = $movement['direction'];
        $quantity = $movement['quantity'];

        if ($direction === 'out') {
            $allowNegative = $context['allow_negative'] ?? (bool) $this->settings->get('inventory.allow_negative_stock', $tenantId);
            if (! $allowNegative && bccomp($balance->quantity, $quantity, 4) < 0) {
                throw new ApiException('INSUFFICIENT_STOCK', "Insufficient stock for product #{$balance->product_id}.", 409);
            }

            $unitCost = $balance->average_cost;
            $newQty = bcsub($balance->quantity, $quantity, 4);
            $newAvgCost = $balance->average_cost;
        } else {
            $unitCost = $movement['unit_cost'] ?? throw new \InvalidArgumentException('unit_cost is required for an incoming stock movement.');
            $currentValue = bcmul($balance->quantity, $balance->average_cost, 4);
            $incomingValue = bcmul($quantity, $unitCost, 4);
            $newQty = bcadd($balance->quantity, $quantity, 4);
            $newAvgCost = bccomp($newQty, '0.0000', 4) > 0
                ? bcdiv(bcadd($currentValue, $incomingValue, 4), $newQty, 4)
                : '0.0000';
        }

        $ledger = StockLedger::create([
            'tenant_id' => $tenantId,
            'uuid' => (string) Str::uuid(),
            'warehouse_id' => $balance->warehouse_id,
            'product_id' => $balance->product_id,
            'variant_id' => $balance->variant_id,
            'batch_id' => $balance->batch_id,
            'direction' => $direction,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'source_type' => $movement['source_type'],
            'source_id' => $movement['source_id'] ?? null,
            'source_line_id' => $movement['source_line_id'] ?? null,
            'balance_after' => $newQty,
            'reason' => $movement['reason'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $balance->quantity = $newQty;
        $balance->average_cost = $newAvgCost;
        $balance->save();

        AuditLogger::log($tenantId, $context['user_id'] ?? null, 'stock.movement', 'StockBalance', $balance->id, null, [
            'direction' => $direction, 'quantity' => $quantity, 'ledger_id' => $ledger->id,
        ]);

        return $ledger;
    }
}
