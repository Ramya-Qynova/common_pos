<?php

namespace App\Services\Purchasing;

use App\Exceptions\ApiException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 8 "Purchasing": "optional PO -> receiving is one transaction".
 * This service only covers the optional PO half of the lifecycle
 * (draft -> ordered); PurchaseReceivingService does the actual stock/ledger
 * work and advances a referenced PO to partially_received/received.
 */
class PurchaseOrderService
{
    public function create(int $tenantId, array $input, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($tenantId, $input, $userId) {
            $po = PurchaseOrder::create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(),
                'supplier_id' => $input['supplier_id'], 'warehouse_id' => $input['warehouse_id'],
                'status' => 'draft', 'created_by' => $userId,
            ]);

            foreach ($input['items'] as $line) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id, 'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'] ?? null, 'unit_id' => $line['unit_id'],
                    'quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost'],
                ]);
            }

            return $po->load('items');
        });
    }

    public function markOrdered(int $tenantId, int $purchaseOrderId, int $userId): PurchaseOrder
    {
        $po = PurchaseOrder::withoutTenantScope()->where('tenant_id', $tenantId)->find($purchaseOrderId);
        if (! $po) {
            throw new ApiException('NOT_FOUND', 'Purchase order not found.', 404);
        }
        if ($po->status !== 'draft') {
            throw new ApiException('CONFLICT', 'Only a draft purchase order can be marked as ordered.', 409);
        }

        $po->status = 'ordered';
        $po->ordered_at = now();
        $po->save();

        return $po;
    }
}
