<?php

namespace App\Services\Purchasing;

use App\Exceptions\ApiException;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 8 "Purchasing": "Purchase returns reverse stock at original cost
 * and create supplier credit." The payable-side mirror of SaleReturnService
 * - same "cannot exceed what was received" guarantee, enforced with a
 * row lock on the purchase_item being returned against.
 */
class PurchaseReturnService
{
    public function __construct(
        private StockService $stock,
        private SupplierLedgerService $supplierLedger,
    ) {}

    /**
     * @param  array<int,array{purchase_item_id:int,quantity:string}>  $items
     */
    public function create(int $tenantId, int $purchaseId, array $items, ?string $reason, int $userId): PurchaseReturn
    {
        $purchase = Purchase::withoutTenantScope()->where('tenant_id', $tenantId)->find($purchaseId);
        if (! $purchase) {
            throw new ApiException('NOT_FOUND', 'Purchase not found.', 404);
        }

        return DB::transaction(function () use ($tenantId, $purchase, $items, $reason, $userId) {
            $totalCredit = '0.0000';
            $movements = [];

            foreach ($items as $item) {
                $purchaseItem = PurchaseItem::where('id', $item['purchase_item_id'])->lockForUpdate()->first();
                if (! $purchaseItem) {
                    throw new ApiException('NOT_FOUND', 'Purchase item not found.', 404);
                }

                $maxReturnable = bcsub($purchaseItem->quantity, $purchaseItem->returned_quantity, 4);
                if (bccomp($item['quantity'], $maxReturnable, 4) > 0) {
                    throw new ApiException('PURCHASE_RETURN_EXCEEDS_RECEIVED', "Cannot return {$item['quantity']} of purchase item #{$purchaseItem->id} - only {$maxReturnable} remains returnable.", 409);
                }

                $lineCredit = bcmul(bcdiv($item['quantity'], $purchaseItem->quantity, 10), $purchaseItem->line_total, 4);
                $totalCredit = bcadd($totalCredit, $lineCredit, 4);

                $movements[] = [
                    'warehouse_id' => $purchase->warehouse_id, 'product_id' => $purchaseItem->product_id,
                    'variant_id' => $purchaseItem->variant_id, 'batch_id' => null,
                    'direction' => 'out', 'quantity' => $item['quantity'], 'source_type' => 'purchase_return',
                ];

                $purchaseItem->returned_quantity = bcadd($purchaseItem->returned_quantity, $item['quantity'], 4);
                $purchaseItem->save();
            }

            $this->stock->recordMovements($tenantId, $movements, ['user_id' => $userId]);

            $return = PurchaseReturn::create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'purchase_id' => $purchase->id,
                'reason' => $reason, 'created_by' => $userId, 'completed_at' => now(),
            ]);

            foreach ($items as $item) {
                PurchaseReturnItem::create([
                    'purchase_return_id' => $return->id, 'purchase_item_id' => $item['purchase_item_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            $this->supplierLedger->recordEntry($tenantId, $purchase->supplier_id, 'credit', $totalCredit, 'purchase_return', $return->id, $reason, $userId);

            AuditLogger::log($tenantId, $userId, 'purchase_return.completed', 'PurchaseReturn', $return->id, null, ['credit_amount' => $totalCredit]);

            return $return->load('items');
        });
    }
}
