<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\CashierShift;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Services\Audit\AuditLogger;
use App\Services\Customers\CustomerLedgerService;
use App\Services\Finance\ShiftService;
use App\Services\Inventory\StockService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 8 "Cancel / Return / Refund / Exchange": state machine requested
 * -> inspected -> completed|rejected (inspection skipped when
 * returns.require_inspection is false - request() lands directly on
 * 'inspected'). "Return validates quantity <= sold - already returned...
 * resellable items re-enter stock at original cost_snapshot, damaged go to
 * a damaged-stock location [deferred - see docs/DECISIONS.md]. Refund
 * amount is computed from original sale values including proportional
 * discount and tax [never current prices - G1]. Cash refunds need an open
 * shift. Provider refunds stay pending until confirmed."
 *
 * The "cannot exceed sold" guarantee is enforced TWICE: a fail-fast check
 * in requestReturn() for a good error message, and the authoritative,
 * row-locked check inside complete()'s transaction - the only place two
 * concurrent completions against the same sale_item could otherwise race.
 */
class SaleReturnService
{
    public function __construct(
        private StockService $stock,
        private CustomerLedgerService $customerLedger,
        private ShiftService $shifts,
        private SettingsService $settings,
    ) {}

    /**
     * @param  array<int,array{sale_item_id:int,quantity:string,condition?:'resellable'|'damaged'}>  $items
     */
    public function requestReturn(int $tenantId, int $saleId, array $items, ?string $reason, int $userId): SaleReturn
    {
        $sale = Sale::withoutTenantScope()->where('tenant_id', $tenantId)->find($saleId);
        if (! $sale) {
            throw new ApiException('NOT_FOUND', 'Sale not found.', 404);
        }

        foreach ($items as $item) {
            $this->assertReturnable($item['sale_item_id'], $item['quantity']);
        }

        $requireInspection = (bool) $this->settings->get('returns.require_inspection', $tenantId);

        return DB::transaction(function () use ($tenantId, $sale, $items, $reason, $userId, $requireInspection) {
            $return = SaleReturn::create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'sale_id' => $sale->id,
                'status' => $requireInspection ? 'requested' : 'inspected',
                'reason' => $reason, 'requested_by' => $userId,
                'inspected_by' => $requireInspection ? null : $userId,
            ]);

            foreach ($items as $item) {
                SaleReturnItem::create([
                    'sale_return_id' => $return->id, 'sale_item_id' => $item['sale_item_id'],
                    'quantity' => $item['quantity'], 'condition' => $item['condition'] ?? 'resellable',
                ]);
            }

            AuditLogger::log($tenantId, $userId, 'sale_return.requested', 'SaleReturn', $return->id, null, ['sale_id' => $sale->id]);

            return $return->load('items');
        });
    }

    public function inspect(int $tenantId, int $saleReturnId, int $userId): SaleReturn
    {
        $return = SaleReturn::withoutTenantScope()->where('tenant_id', $tenantId)->find($saleReturnId);
        if (! $return) {
            throw new ApiException('NOT_FOUND', 'Return not found.', 404);
        }
        if ($return->status !== 'requested') {
            throw new ApiException('CONFLICT', 'Only a requested return can be inspected.', 409);
        }

        $return->status = 'inspected';
        $return->inspected_by = $userId;
        $return->save();

        return $return;
    }

    public function complete(int $tenantId, int $saleReturnId, int $userId, string $refundMethod = 'original'): Refund
    {
        $return = SaleReturn::withoutTenantScope()->where('tenant_id', $tenantId)->with('items')->find($saleReturnId);
        if (! $return) {
            throw new ApiException('NOT_FOUND', 'Return not found.', 404);
        }
        if ($return->status !== 'inspected') {
            throw new ApiException('CONFLICT', 'A return must be inspected before it can be completed.', 409);
        }

        $sale = Sale::withoutTenantScope()->find($return->sale_id);

        return DB::transaction(function () use ($tenantId, $return, $sale, $userId, $refundMethod) {
            $totalRefund = '0.0000';
            $movements = [];

            foreach ($return->items as $item) {
                $saleItem = SaleItem::where('id', $item->sale_item_id)->lockForUpdate()->first();

                $alreadyReturned = (string) DB::table('sale_return_items')
                    ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
                    ->where('sale_return_items.sale_item_id', $saleItem->id)
                    ->where('sale_returns.status', 'completed')
                    ->sum('sale_return_items.quantity');

                $maxReturnable = bcsub($saleItem->quantity, $alreadyReturned, 4);
                if (bccomp($item->quantity, $maxReturnable, 4) > 0) {
                    throw new ApiException('RETURN_EXCEEDS_SOLD', "Cannot return {$item->quantity} of sale item #{$saleItem->id} - only {$maxReturnable} remains returnable.", 409);
                }

                // Refund share of the ORIGINAL line total (taxable + tax), proportional to quantity -
                // never recomputed from current prices (G1). Multiply before dividing (not
                // qty/soldQty truncated to a ratio first) - computing the ratio first truncates
                // bcdiv's result to scale 4 (e.g. 1/3 -> 0.3333) and then truncates AGAIN on the
                // final bcmul, losing a cent on exact-division cases like 1/3 of 330.0000. A single
                // division at the end avoids compounding two truncations.
                $lineRefund = bcdiv(bcmul($item->quantity, $saleItem->line_total, 8), $saleItem->quantity, 4);
                $totalRefund = bcadd($totalRefund, $lineRefund, 4);

                if ($item->condition === 'resellable') {
                    $movements[] = [
                        'warehouse_id' => $sale->warehouse_id, 'product_id' => $saleItem->product_id,
                        'variant_id' => $saleItem->variant_id, 'batch_id' => $saleItem->batch_id,
                        'direction' => 'in', 'quantity' => $item->quantity, 'unit_cost' => $saleItem->cost_snapshot,
                        'source_type' => 'sale_return', 'source_id' => $return->id, 'source_line_id' => $item->id,
                    ];
                }
                // damaged items are deliberately NOT restored to sellable stock this phase - a
                // write-off, since no dedicated damaged-stock-location model exists yet (see docs/DECISIONS.md).
            }

            if (! empty($movements)) {
                $this->stock->recordMovements($tenantId, $movements, ['user_id' => $userId]);
            }

            $return->status = 'completed';
            $return->completed_at = now();
            $return->save();

            $method = $refundMethod === 'original' ? $this->originalPaymentMethodCode($sale) : $refundMethod;
            $refundStatus = 'pending';

            if ($method === 'cash') {
                if (! $sale->cashier_shift_id) {
                    throw new ApiException('SHIFT_REQUIRED', 'A cash refund requires an open shift.', 409);
                }
                $shift = CashierShift::withoutTenantScope()->find($sale->cashier_shift_id);
                if (! $shift || ! $shift->isOpen()) {
                    throw new ApiException('CONFLICT', 'Cash refunds need an open shift.', 409);
                }
                $this->shifts->recordCashMovement($tenantId, $shift->id, 'cash_refund', $totalRefund, 'return', $userId, 'sale_return', $return->id);
                $refundStatus = 'completed';
            } elseif ($method === 'credit') {
                $this->customerLedger->recordEntry($tenantId, $sale->customer_id, 'credit', $totalRefund, 'sale_return', $return->id, null, $userId);
                $refundStatus = 'completed';
            }
            // any other provider method stays 'pending' until a webhook/manual confirmation lands (section 8) - no adapter exists yet (D-0022).

            $refund = Refund::create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'sale_id' => $sale->id,
                'sale_return_id' => $return->id, 'amount' => $totalRefund, 'method' => $method,
                'status' => $refundStatus, 'processed_by' => $userId,
                'processed_at' => $refundStatus === 'completed' ? now() : null,
            ]);

            AuditLogger::log($tenantId, $userId, 'sale_return.completed', 'SaleReturn', $return->id, null, ['refund_amount' => $totalRefund]);

            return $refund;
        });
    }

    private function assertReturnable(int $saleItemId, string $quantity): void
    {
        $saleItem = SaleItem::find($saleItemId);
        if (! $saleItem) {
            throw new ApiException('NOT_FOUND', 'Sale item not found.', 404);
        }

        $alreadyReturned = (string) DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->where('sale_return_items.sale_item_id', $saleItemId)
            ->where('sale_returns.status', 'completed')
            ->sum('sale_return_items.quantity');

        $maxReturnable = bcsub($saleItem->quantity, $alreadyReturned, 4);
        if (bccomp($quantity, $maxReturnable, 4) > 0) {
            throw new ApiException('RETURN_EXCEEDS_SOLD', "Cannot return {$quantity} of sale item #{$saleItemId} - only {$maxReturnable} remains returnable.", 409);
        }
    }

    private function originalPaymentMethodCode(Sale $sale): string
    {
        $payment = SalePayment::where('sale_id', $sale->id)->first();
        if (! $payment) {
            return 'cash';
        }

        return PaymentMethod::withoutTenantScope()->find($payment->payment_method_id)->code;
    }
}
