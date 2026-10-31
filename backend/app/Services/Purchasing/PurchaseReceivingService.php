<?php

namespace App\Services\Purchasing;

use App\Exceptions\ApiException;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 8 "Purchasing": "receiving is one transaction (validate
 * supplier/items/cost/tax, create purchase, lock, ledger rows at received
 * cost, ... update PO status, compute payable; ... unpaid -> supplier
 * ledger payable)." This is what finally gives supplier_ledger debits a
 * real source (`purchase`) instead of only opening balances/manual entries
 * (D-0016/D-0022's open loop). A client-submitted total is never trusted -
 * subtotal/tax/grand total are always recomputed server-side from the
 * line quantities/costs actually received (G1).
 */
class PurchaseReceivingService
{
    public function __construct(
        private StockService $stock,
        private SupplierLedgerService $supplierLedger,
    ) {}

    /**
     * @param  array<int,array{product_id:int,variant_id?:int|null,unit_id:int,quantity:string,unit_cost:string,tax_amount?:string}>  $items
     */
    public function receive(int $tenantId, int $supplierId, int $warehouseId, array $items, int $userId, ?int $purchaseOrderId = null): Purchase
    {
        $supplier = Supplier::withoutTenantScope()->where('tenant_id', $tenantId)->find($supplierId);
        if (! $supplier) {
            throw new ApiException('NOT_FOUND', 'Supplier not found.', 404);
        }

        $po = null;
        if ($purchaseOrderId !== null) {
            $po = PurchaseOrder::withoutTenantScope()->where('tenant_id', $tenantId)->with('items')->find($purchaseOrderId);
            if (! $po) {
                throw new ApiException('NOT_FOUND', 'Purchase order not found.', 404);
            }
            if (! in_array($po->status, ['ordered', 'partially_received'], true)) {
                throw new ApiException('CONFLICT', 'This purchase order is not open for receiving.', 409);
            }
        }

        return DB::transaction(function () use ($tenantId, $supplier, $warehouseId, $items, $userId, $po) {
            $subtotal = '0.0000';
            $taxTotal = '0.0000';
            $grandTotal = '0.0000';
            $movements = [];

            foreach ($items as $line) {
                $lineBeforeTax = bcmul($line['quantity'], $line['unit_cost'], 4);
                $taxAmount = $line['tax_amount'] ?? '0.0000';
                $subtotal = bcadd($subtotal, $lineBeforeTax, 4);
                $taxTotal = bcadd($taxTotal, $taxAmount, 4);
                $grandTotal = bcadd($grandTotal, bcadd($lineBeforeTax, $taxAmount, 4), 4);

                $movements[] = [
                    'warehouse_id' => $warehouseId, 'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'] ?? null, 'batch_id' => null,
                    'direction' => 'in', 'quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost'],
                    'source_type' => 'purchase',
                ];
            }

            $this->stock->recordMovements($tenantId, $movements, ['user_id' => $userId]);

            $purchase = Purchase::create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(),
                'purchase_order_id' => $po?->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouseId,
                'subtotal' => $subtotal, 'tax_total' => $taxTotal, 'grand_total' => $grandTotal,
                'received_by' => $userId, 'received_at' => now(),
            ]);

            foreach ($items as $line) {
                $lineBeforeTax = bcmul($line['quantity'], $line['unit_cost'], 4);
                $taxAmount = $line['tax_amount'] ?? '0.0000';
                PurchaseItem::create([
                    'purchase_id' => $purchase->id, 'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'] ?? null, 'unit_id' => $line['unit_id'],
                    'quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost'],
                    'tax_amount' => $taxAmount, 'line_total' => bcadd($lineBeforeTax, $taxAmount, 4),
                ]);

                if ($po) {
                    $poItem = $po->items->first(fn (PurchaseOrderItem $i) => $i->product_id === $line['product_id'] && $i->variant_id === ($line['variant_id'] ?? null));
                    if ($poItem) {
                        $poItem->received_quantity = bcadd($poItem->received_quantity, $line['quantity'], 4);
                        $poItem->save();
                    }
                }
            }

            if ($po) {
                $po->refresh()->load('items');
                $fullyReceived = $po->items->every(fn (PurchaseOrderItem $i) => bccomp($i->received_quantity, $i->quantity, 4) >= 0);
                $po->status = $fullyReceived ? 'received' : 'partially_received';
                $po->save();
            }

            $this->supplierLedger->recordEntry($tenantId, $supplier->id, 'debit', $grandTotal, 'purchase', $purchase->id, null, $userId);

            AuditLogger::log($tenantId, $userId, 'purchase.received', 'Purchase', $purchase->id, null, ['grand_total' => $grandTotal]);

            return $purchase->load('items');
        });
    }
}
