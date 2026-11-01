<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockReconciliation;
use App\Models\StockReconciliationItem;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockReconciliationService
{
    public function __construct(private StockService $stock) {}

    /**
     * @param  array<int,int>|null  $productIds
     */
    public function start(
        int $tenantId,
        int $warehouseId,
        int $userId,
        ?array $productIds = null,
        ?string $notes = null
    ): StockReconciliation {
        return DB::transaction(function () use ($tenantId, $warehouseId, $userId, $productIds, $notes) {
            $refNumber = 'REC-'.strtoupper(Str::random(8));

            $reconciliation = StockReconciliation::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'reference_number' => $refNumber,
                'warehouse_id' => $warehouseId,
                'status' => 'in_progress',
                'created_by' => $userId,
                'notes' => $notes,
            ]);

            $productsQuery = Product::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true);

            if ($productIds) {
                $productsQuery->whereIn('id', $productIds);
            }

            $products = $productsQuery->get();

            $balances = StockBalance::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->get()
                ->keyBy(fn (StockBalance $b) => "{$b->product_id}-{$b->variant_key}-{$b->batch_key}");

            foreach ($products as $product) {
                $key = "{$product->id}-0-0";
                $balance = $balances->get($key);

                $systemQty = $balance ? (string) $balance->quantity : '0.0000';
                $avgCost = $balance ? (string) $balance->average_cost : '0.0000';

                StockReconciliationItem::create([
                    'stock_reconciliation_id' => $reconciliation->id,
                    'product_id' => $product->id,
                    'variant_id' => null,
                    'batch_id' => null,
                    'system_quantity' => $systemQty,
                    'counted_quantity' => null,
                    'difference_quantity' => '0.0000',
                    'unit_cost' => $avgCost,
                    'variance_value' => '0.0000',
                ]);
            }

            AuditLogger::log($tenantId, $userId, 'stock_reconciliation.started', 'StockReconciliation', $reconciliation->id, null, [
                'reference_number' => $refNumber,
                'warehouse_id' => $warehouseId,
                'item_count' => count($products),
            ]);

            return $reconciliation->load(['items.product', 'warehouse']);
        });
    }

    /**
     * @param  array<int,array{item_id:int,counted_quantity:string}>  $counts
     */
    public function submitCounts(int $tenantId, int $reconciliationId, array $counts, int $userId): StockReconciliation
    {
        return DB::transaction(function () use ($tenantId, $reconciliationId, $counts, $userId) {
            /** @var StockReconciliation|null $reconciliation */
            $reconciliation = StockReconciliation::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->find($reconciliationId);

            if (! $reconciliation) {
                throw new ApiException('NOT_FOUND', 'errors.reconciliation_not_found', 'Reconciliation record not found.', 404);
            }

            if ($reconciliation->status !== 'in_progress') {
                throw new \InvalidArgumentException("Cannot update counts for reconciliation with status: {$reconciliation->status}.");
            }

            $countsMap = [];
            foreach ($counts as $c) {
                $countsMap[$c['item_id']] = bcadd((string) $c['counted_quantity'], '0', 4);
            }

            $items = $reconciliation->items()->get();

            foreach ($items as $item) {
                if (isset($countsMap[$item->id])) {
                    $counted = $countsMap[$item->id];
                    $difference = bcsub($counted, (string) $item->system_quantity, 4);
                    $varianceValue = bcmul($difference, (string) $item->unit_cost, 4);

                    $item->update([
                        'counted_quantity' => $counted,
                        'difference_quantity' => $difference,
                        'variance_value' => $varianceValue,
                    ]);
                }
            }

            AuditLogger::log($tenantId, $userId, 'stock_reconciliation.counts_submitted', 'StockReconciliation', $reconciliation->id, null, [
                'counts_count' => count($counts),
            ]);

            return $reconciliation->load(['items.product', 'warehouse']);
        });
    }

    public function complete(int $tenantId, int $reconciliationId, int $userId): StockReconciliation
    {
        return DB::transaction(function () use ($tenantId, $reconciliationId, $userId) {
            /** @var StockReconciliation|null $reconciliation */
            $reconciliation = StockReconciliation::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->find($reconciliationId);

            if (! $reconciliation) {
                throw new ApiException('NOT_FOUND', 'errors.reconciliation_not_found', 'Reconciliation record not found.', 404);
            }

            if ($reconciliation->status !== 'in_progress') {
                throw new \InvalidArgumentException("Cannot complete reconciliation with status: {$reconciliation->status}.");
            }

            $items = $reconciliation->items()->get();
            $movements = [];

            foreach ($items as $item) {
                if ($item->counted_quantity === null) {
                    continue;
                }

                $diffComp = bccomp((string) $item->difference_quantity, '0.0000', 4);
                if ($diffComp === 0) {
                    continue;
                }

                if ($diffComp > 0) {
                    $movements[] = [
                        'warehouse_id' => $reconciliation->warehouse_id,
                        'product_id' => $item->product_id,
                        'variant_id' => $item->variant_id,
                        'batch_id' => $item->batch_id,
                        'direction' => 'in',
                        'quantity' => (string) $item->difference_quantity,
                        'unit_cost' => (string) $item->unit_cost,
                        'source_type' => 'reconciliation',
                        'source_id' => $reconciliation->id,
                        'source_line_id' => $item->id,
                        'reason' => "Physical count surplus on {$reconciliation->reference_number}",
                    ];
                } else {
                    $movements[] = [
                        'warehouse_id' => $reconciliation->warehouse_id,
                        'product_id' => $item->product_id,
                        'variant_id' => $item->variant_id,
                        'batch_id' => $item->batch_id,
                        'direction' => 'out',
                        'quantity' => bcmul((string) $item->difference_quantity, '-1', 4),
                        'unit_cost' => (string) $item->unit_cost,
                        'source_type' => 'reconciliation',
                        'source_id' => $reconciliation->id,
                        'source_line_id' => $item->id,
                        'reason' => "Physical count deficit on {$reconciliation->reference_number}",
                    ];
                }
            }

            if (! empty($movements)) {
                $this->stock->recordMovements($tenantId, $movements, ['user_id' => $userId]);
            }

            $reconciliation->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $userId,
            ]);

            AuditLogger::log($tenantId, $userId, 'stock_reconciliation.completed', 'StockReconciliation', $reconciliation->id, null, [
                'reference_number' => $reconciliation->reference_number,
                'adjustments_made' => count($movements),
            ]);

            return $reconciliation->load(['items.product', 'warehouse']);
        });
    }

    public function cancel(int $tenantId, int $reconciliationId, int $userId, string $reason): StockReconciliation
    {
        return DB::transaction(function () use ($tenantId, $reconciliationId, $userId, $reason) {
            /** @var StockReconciliation|null $reconciliation */
            $reconciliation = StockReconciliation::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->find($reconciliationId);

            if (! $reconciliation) {
                throw new ApiException('NOT_FOUND', 'errors.reconciliation_not_found', 'Reconciliation record not found.', 404);
            }

            if ($reconciliation->status === 'completed') {
                throw new \InvalidArgumentException('Cannot cancel an already completed reconciliation.');
            }

            $reconciliation->update([
                'status' => 'cancelled',
                'notes' => trim(($reconciliation->notes ?? '')."\nCancellation reason: {$reason}"),
            ]);

            AuditLogger::log($tenantId, $userId, 'stock_reconciliation.cancelled', 'StockReconciliation', $reconciliation->id, null, [
                'reason' => $reason,
            ]);

            return $reconciliation->load(['items.product', 'warehouse']);
        });
    }
}
