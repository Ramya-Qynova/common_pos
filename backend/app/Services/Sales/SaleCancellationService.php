<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\ApprovalRequest;
use App\Models\CashierShift;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleCancellation;
use App\Services\Audit\AuditLogger;
use App\Services\Customers\CustomerLedgerService;
use App\Services\Finance\ShiftService;
use App\Services\Inventory\StockService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 8 "Cancel / Return / Refund / Exchange": "Cancel reverses the
 * whole sale in one transaction (reversing stock, payment, customer-ledger
 * entries; invoice number stays in series marked cancelled), allowed only
 * within sales.cancel_window with permission and approval." A user without
 * pos.sales.cancel gets the same APPROVAL_REQUIRED treatment SaleService
 * uses for discounts (G7 - nothing here half-completes: either the whole
 * reversal lands, or the sale is untouched and an approval request exists).
 */
class SaleCancellationService
{
    public function __construct(
        private StockService $stock,
        private CustomerLedgerService $customerLedger,
        private ShiftService $shifts,
        private SettingsService $settings,
    ) {}

    public function cancel(int $tenantId, int $saleId, ?string $reason, int $userId, bool $hasPermission): SaleCancellation
    {
        $sale = Sale::withoutTenantScope()->where('tenant_id', $tenantId)->with('items', 'payments')->find($saleId);
        if (! $sale) {
            throw new ApiException('NOT_FOUND', 'Sale not found.', 404);
        }
        if ($sale->status === 'cancelled') {
            throw new ApiException('CONFLICT', 'This sale is already cancelled.', 409);
        }

        $windowMinutes = (int) $this->settings->get('sales.cancel_window', $tenantId);
        if ($sale->server_created_at->diffInMinutes(now()) > $windowMinutes) {
            throw new ApiException('CANCEL_WINDOW_EXPIRED', "This sale can no longer be cancelled - the {$windowMinutes}-minute cancel window has passed.", 409);
        }

        if (! $hasPermission) {
            ApprovalRequest::withoutTenantScope()->create([
                'tenant_id' => $tenantId, 'rule_type' => 'cancellation', 'action_type' => 'sale.cancel',
                'action_payload' => ['sale_id' => $saleId, 'reason' => $reason], 'requested_by' => $userId, 'status' => 'pending',
            ]);
            throw new ApiException('APPROVAL_REQUIRED', 'Cancelling this sale requires approval.', 403);
        }

        return DB::transaction(function () use ($tenantId, $sale, $reason, $userId) {
            $movements = $sale->items->map(fn ($item) => [
                'warehouse_id' => $sale->warehouse_id, 'product_id' => $item->product_id,
                'variant_id' => $item->variant_id, 'batch_id' => $item->batch_id,
                'direction' => 'in', 'quantity' => $item->quantity, 'unit_cost' => $item->cost_snapshot,
                'source_type' => 'sale_cancellation', 'source_id' => $sale->id,
            ])->all();
            $this->stock->recordMovements($tenantId, $movements, ['user_id' => $userId]);

            foreach ($sale->payments as $payment) {
                $method = PaymentMethod::withoutTenantScope()->find($payment->payment_method_id);
                $amountPaid = bcsub($payment->amount, $payment->change_given, 4);

                if ($method->code === 'credit') {
                    $this->customerLedger->recordEntry(
                        $tenantId, $sale->customer_id, 'credit', $amountPaid,
                        'sale_cancellation', $sale->id, $reason, $userId,
                    );
                } elseif ($method->code === 'cash' && $sale->cashier_shift_id) {
                    $shift = CashierShift::withoutTenantScope()->find($sale->cashier_shift_id);
                    if ($shift && $shift->isOpen()) {
                        $this->shifts->recordCashMovement(
                            $tenantId, $shift->id, 'cash_refund', $amountPaid, $reason, $userId, 'sale_cancellation', $sale->id,
                        );
                    }
                }
            }

            $sale->status = 'cancelled';
            $sale->payment_status = 'refunded';
            $sale->save();

            $cancellation = SaleCancellation::create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'sale_id' => $sale->id,
                'reason' => $reason, 'requested_by' => $userId, 'approved_by' => $userId, 'cancelled_at' => now(),
            ]);

            AuditLogger::log($tenantId, $userId, 'sale.cancelled', 'Sale', $sale->id, null, ['reason' => $reason]);

            return $cancellation;
        });
    }
}
