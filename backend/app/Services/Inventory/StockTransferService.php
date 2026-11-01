<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\StockBalance;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockTransferService
{
    public function __construct(private StockService $stock) {}

    /**
     * @param  array<int,array{product_id:int,variant_id?:int|null,batch_id?:int|null,quantity:string,notes?:string|null}>  $items
     */
    public function create(
        int $tenantId,
        int $sourceWarehouseId,
        int $destWarehouseId,
        array $items,
        int $userId,
        ?string $notes = null,
        bool $autoDispatch = false
    ): StockTransfer {
        if ($sourceWarehouseId === $destWarehouseId) {
            throw new \InvalidArgumentException('Source and destination warehouse cannot be the same.');
        }

        if (empty($items)) {
            throw new \InvalidArgumentException('Transfer must contain at least one item.');
        }

        return DB::transaction(function () use (
            $tenantId,
            $sourceWarehouseId,
            $destWarehouseId,
            $items,
            $userId,
            $notes,
            $autoDispatch
        ) {
            $transferNumber = 'TRF-'.strtoupper(Str::random(8));

            $transfer = StockTransfer::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'transfer_number' => $transferNumber,
                'source_warehouse_id' => $sourceWarehouseId,
                'destination_warehouse_id' => $destWarehouseId,
                'status' => 'draft',
                'created_by' => $userId,
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'batch_id' => $item['batch_id'] ?? null,
                    'quantity_dispatched' => bcadd((string) $item['quantity'], '0', 4),
                    'quantity_received' => '0.0000',
                    'discrepancy_quantity' => '0.0000',
                    'unit_cost' => '0.0000',
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            AuditLogger::log($tenantId, $userId, 'stock_transfer.created', 'StockTransfer', $transfer->id, null, [
                'transfer_number' => $transferNumber,
                'source_warehouse_id' => $sourceWarehouseId,
                'destination_warehouse_id' => $destWarehouseId,
                'item_count' => count($items),
            ]);

            if ($autoDispatch) {
                return $this->dispatch($tenantId, $transfer->id, $userId);
            }

            return $transfer->load(['items.product', 'sourceWarehouse', 'destinationWarehouse']);
        });
    }

    public function dispatch(int $tenantId, int $transferId, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($tenantId, $transferId, $userId) {
            /** @var StockTransfer|null $transfer */
            $transfer = StockTransfer::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->find($transferId);

            if (! $transfer) {
                throw new ApiException('NOT_FOUND', 'errors.transfer_not_found', 'Stock transfer not found.', 404);
            }

            if ($transfer->status !== 'draft') {
                throw new \InvalidArgumentException("Transfer cannot be dispatched from status: {$transfer->status}.");
            }

            $items = $transfer->items()->get();
            $movements = [];

            foreach ($items as $item) {
                $balance = StockBalance::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->where('warehouse_id', $transfer->source_warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->where('variant_key', $item->variant_id ?? 0)
                    ->where('batch_key', $item->batch_id ?? 0)
                    ->first();

                $unitCost = $balance ? (string) $balance->average_cost : '0.0000';
                $item->update(['unit_cost' => $unitCost]);

                $movements[] = [
                    'warehouse_id' => $transfer->source_warehouse_id,
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'batch_id' => $item->batch_id,
                    'direction' => 'out',
                    'quantity' => (string) $item->quantity_dispatched,
                    'unit_cost' => $unitCost,
                    'source_type' => 'transfer_out',
                    'source_id' => $transfer->id,
                    'source_line_id' => $item->id,
                    'reason' => "Transfer {$transfer->transfer_number} dispatched to warehouse {$transfer->destination_warehouse_id}",
                ];
            }

            $this->stock->recordMovements($tenantId, $movements, ['user_id' => $userId]);

            $transfer->update([
                'status' => 'dispatched',
                'dispatched_at' => now(),
                'dispatched_by' => $userId,
            ]);

            AuditLogger::log($tenantId, $userId, 'stock_transfer.dispatched', 'StockTransfer', $transfer->id, null, [
                'transfer_number' => $transfer->transfer_number,
            ]);

            return $transfer->load(['items.product', 'sourceWarehouse', 'destinationWarehouse']);
        });
    }

    /**
     * @param  array<int,array{item_id:int,quantity_received:string}>|null  $receivedItems
     */
    public function receive(
        int $tenantId,
        int $transferId,
        ?array $receivedItems,
        int $userId,
        ?string $notes = null
    ): StockTransfer {
        return DB::transaction(function () use ($tenantId, $transferId, $receivedItems, $userId, $notes) {
            /** @var StockTransfer|null $transfer */
            $transfer = StockTransfer::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->find($transferId);

            if (! $transfer) {
                throw new ApiException('NOT_FOUND', 'errors.transfer_not_found', 'Stock transfer not found.', 404);
            }

            if ($transfer->status !== 'dispatched') {
                throw new \InvalidArgumentException("Transfer cannot be received from status: {$transfer->status}.");
            }

            $items = $transfer->items()->get();
            $receivedMap = [];
            if ($receivedItems) {
                foreach ($receivedItems as $ri) {
                    $receivedMap[$ri['item_id']] = (string) $ri['quantity_received'];
                }
            }

            $movements = [];
            $hasDiscrepancy = false;

            foreach ($items as $item) {
                $qtyReceived = isset($receivedMap[$item->id])
                    ? bcadd($receivedMap[$item->id], '0', 4)
                    : (string) $item->quantity_dispatched;

                if (bccomp($qtyReceived, (string) $item->quantity_dispatched, 4) > 0) {
                    throw new \InvalidArgumentException("Received quantity ({$qtyReceived}) cannot exceed dispatched quantity ({$item->quantity_dispatched}).");
                }

                $discrepancy = bcsub((string) $item->quantity_dispatched, $qtyReceived, 4);
                if (bccomp($discrepancy, '0.0000', 4) > 0) {
                    $hasDiscrepancy = true;
                }

                $item->update([
                    'quantity_received' => $qtyReceived,
                    'discrepancy_quantity' => $discrepancy,
                ]);

                if (bccomp($qtyReceived, '0.0000', 4) > 0) {
                    $movements[] = [
                        'warehouse_id' => $transfer->destination_warehouse_id,
                        'product_id' => $item->product_id,
                        'variant_id' => $item->variant_id,
                        'batch_id' => $item->batch_id,
                        'direction' => 'in',
                        'quantity' => $qtyReceived,
                        'unit_cost' => (string) $item->unit_cost,
                        'source_type' => 'transfer_in',
                        'source_id' => $transfer->id,
                        'source_line_id' => $item->id,
                        'reason' => "Transfer {$transfer->transfer_number} received from warehouse {$transfer->source_warehouse_id}",
                    ];
                }
            }

            if (! empty($movements)) {
                $this->stock->recordMovements($tenantId, $movements, ['user_id' => $userId]);
            }

            $newStatus = $hasDiscrepancy ? 'partially_received' : 'received';

            $transfer->update([
                'status' => $newStatus,
                'received_at' => now(),
                'received_by' => $userId,
                'notes' => $notes ?? $transfer->notes,
            ]);

            AuditLogger::log($tenantId, $userId, 'stock_transfer.received', 'StockTransfer', $transfer->id, null, [
                'status' => $newStatus,
                'has_discrepancy' => $hasDiscrepancy,
            ]);

            return $transfer->load(['items.product', 'sourceWarehouse', 'destinationWarehouse']);
        });
    }

    public function cancel(int $tenantId, int $transferId, int $userId, string $reason): StockTransfer
    {
        return DB::transaction(function () use ($tenantId, $transferId, $userId, $reason) {
            /** @var StockTransfer|null $transfer */
            $transfer = StockTransfer::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->find($transferId);

            if (! $transfer) {
                throw new ApiException('NOT_FOUND', 'errors.transfer_not_found', 'Stock transfer not found.', 404);
            }

            if (in_array($transfer->status, ['received', 'partially_received'], true)) {
                throw new \InvalidArgumentException('Cannot cancel a transfer that has already been received.');
            }

            if ($transfer->status === 'dispatched') {
                $items = $transfer->items()->get();
                $movements = [];

                foreach ($items as $item) {
                    $movements[] = [
                        'warehouse_id' => $transfer->source_warehouse_id,
                        'product_id' => $item->product_id,
                        'variant_id' => $item->variant_id,
                        'batch_id' => $item->batch_id,
                        'direction' => 'in',
                        'quantity' => (string) $item->quantity_dispatched,
                        'unit_cost' => (string) $item->unit_cost,
                        'source_type' => 'transfer_cancel',
                        'source_id' => $transfer->id,
                        'source_line_id' => $item->id,
                        'reason' => "Transfer {$transfer->transfer_number} cancelled: {$reason}",
                    ];
                }

                $this->stock->recordMovements($tenantId, $movements, ['user_id' => $userId]);
            }

            $transfer->update([
                'status' => 'cancelled',
                'notes' => trim(($transfer->notes ?? '')."\nCancellation reason: {$reason}"),
            ]);

            AuditLogger::log($tenantId, $userId, 'stock_transfer.cancelled', 'StockTransfer', $transfer->id, null, [
                'reason' => $reason,
            ]);

            return $transfer->load(['items.product', 'sourceWarehouse', 'destinationWarehouse']);
        });
    }
}
