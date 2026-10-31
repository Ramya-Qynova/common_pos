<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemTax;
use App\Models\SalePayment;
use App\Models\StockBalance;
use App\Models\TaxGroup;
use App\Services\Audit\AuditLogger;
use App\Services\Customers\CustomerLedgerService;
use App\Services\Finance\ShiftService;
use App\Services\Inventory\StockService;
use App\Services\Pricing\PricingEngine;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 8 "POS sale (online)": the full 14-step sequence, minus the
 * idempotency-key bookkeeping itself (handled by EnsureIdempotent
 * middleware, which wraps this call). G1: client-submitted prices/totals
 * are never read from $input - every price comes from ProductPrice/
 * TaxGroup lookups, every total from PricingEngine. A thrown exception
 * anywhere in here rolls back the whole transaction (G4): no sale, no
 * stock movement, no ledger entry, no invoice number consumed.
 */
class SaleService
{
    public function __construct(
        private PricingEngine $pricing,
        private StockService $stock,
        private NumberingService $numbering,
        private SettingsService $settings,
        private CustomerLedgerService $customerLedger,
        private ShiftService $shifts,
    ) {}

    public function create(int $tenantId, array $input, int $userId, bool $hasDiscountPermission = false): Sale
    {
        if ((bool) $this->settings->get('sales.shift_required', $tenantId) && empty($input['cashier_shift_id'])) {
            throw new ApiException('SHIFT_REQUIRED', 'An open shift is required to create a sale.', 409);
        }

        // section 8 step 4: "discounts (permission sales.discount +
        // discount_rules, or create an approval request)". A cashier
        // without the permission never gets to complete a discounted sale
        // silently at a lower price - the sale is blocked and an approval
        // request is recorded instead (G7: one entity's status never
        // silently changes another's - nothing here is "half completed").
        if (! $hasDiscountPermission && $this->requestsAnyDiscount($input)) {
            ApprovalRequest::withoutTenantScope()->create([
                'tenant_id' => $tenantId, 'rule_type' => 'discount', 'action_type' => 'sale.discount',
                'action_payload' => ['input' => $input], 'requested_by' => $userId, 'status' => 'pending',
            ]);
            throw new ApiException('APPROVAL_REQUIRED', 'This discount requires approval before the sale can be completed.', 403);
        }

        $priceListId = $input['price_list_id'];
        $warehouseId = $input['warehouse_id'];
        $resolvedLines = array_map(fn ($line) => $this->resolveLine($tenantId, $priceListId, $warehouseId, $line), $input['lines']);

        $pricingInput = [
            'lines' => array_map(fn ($rl) => [
                'unit_price' => $rl['unit_price'],
                'quantity' => $rl['quantity'],
                'line_discount' => $rl['line_discount'] ?? ['type' => 'none', 'value' => '0.0000'],
                'tax_group' => $rl['tax_group_key'],
            ], $resolvedLines),
            'invoice_discount' => $input['invoice_discount'] ?? ['type' => 'none', 'value' => '0.0000'],
            'tax_groups' => array_combine(array_map(fn ($rl) => $rl['tax_group_key'], $resolvedLines), array_map(fn ($rl) => $rl['tax_group_shape'], $resolvedLines)),
            'rounding' => [
                'mode' => (string) $this->settings->get('locale.rounding_mode', $tenantId),
                'cash_step' => (string) $this->settings->get('locale.cash_rounding_step', $tenantId),
            ],
        ];

        $priced = $this->pricing->calculate($pricingInput);

        $this->validatePayments($tenantId, $input['payments'], $priced['grand_total'], $input['customer_id'] ?? null);

        return DB::transaction(function () use ($tenantId, $input, $resolvedLines, $priced, $userId) {
            $stockMovements = [];
            foreach ($resolvedLines as $index => $rl) {
                $stockMovements[] = [
                    'warehouse_id' => $input['warehouse_id'], 'product_id' => $rl['product_id'],
                    'variant_id' => $rl['variant_id'], 'batch_id' => null,
                    'direction' => 'out', 'quantity' => $rl['quantity'], 'source_type' => 'sale',
                ];
            }
            $ledgerEntries = $this->stock->recordMovements($tenantId, $stockMovements, ['user_id' => $userId]);

            $isTest = $input['is_test'] ?? false;
            $series = $isTest ? 'TEST' : 'default';
            $invoiceNumber = $this->numbering->next($tenantId, $series);

            $sale = Sale::withoutTenantScope()->create([
                'tenant_id' => $tenantId, 'uuid' => $input['uuid'] ?? (string) Str::uuid(),
                'branch_id' => $input['branch_id'], 'warehouse_id' => $input['warehouse_id'],
                'register_id' => $input['register_id'], 'cashier_shift_id' => $input['cashier_shift_id'] ?? null,
                'customer_id' => $input['customer_id'] ?? null, 'status' => 'completed', 'payment_status' => 'paid',
                'line_subtotal' => $priced['line_subtotal'], 'discount_total' => $priced['discount_total'],
                'taxable_amount' => $priced['taxable_amount'], 'tax_total' => $priced['tax_total'],
                'grand_total' => $priced['grand_total'], 'is_test' => $isTest, 'origin' => 'online',
                'server_created_at' => now(), 'created_by' => $userId,
            ]);

            Invoice::withoutTenantScope()->create([
                'tenant_id' => $tenantId, 'sale_id' => $sale->id, 'series' => $series,
                'fiscal_year' => $invoiceNumber['fiscal_year'], 'number' => $invoiceNumber['number'],
                'prefix' => $invoiceNumber['prefix'], 'full_number' => ($invoiceNumber['prefix'] ?? '').$invoiceNumber['number'],
            ]);

            foreach ($resolvedLines as $index => $rl) {
                $lineResult = $priced['lines'][$index];
                $saleItem = SaleItem::create([
                    'sale_id' => $sale->id, 'product_id' => $rl['product_id'], 'variant_id' => $rl['variant_id'],
                    'batch_id' => null, 'unit_id' => $rl['unit_id'], 'quantity' => $rl['quantity'],
                    'unit_price' => $rl['unit_price'], 'line_discount_amount' => $lineResult['line_discount'],
                    'taxable_amount' => $lineResult['taxable_amount'], 'tax_amount' => $lineResult['tax_amount'],
                    'line_total' => $lineResult['line_total'], 'cost_snapshot' => $rl['cost_snapshot'],
                    'price_source' => $rl['price_source'],
                ]);

                foreach ($lineResult['tax_breakdown'] as $tax) {
                    SaleItemTax::create(['sale_item_id' => $saleItem->id, 'tax_code' => $tax['code'], 'amount' => $tax['amount']]);
                }
            }

            $this->applyPayments($tenantId, $sale, $input['payments'], $userId, $input);

            AuditLogger::log($tenantId, $userId, 'sale.created', 'Sale', $sale->id, null, ['grand_total' => $priced['grand_total']]);

            return $sale->load('items', 'payments', 'invoice');
        });
    }

    private function requestsAnyDiscount(array $input): bool
    {
        if (($input['invoice_discount']['type'] ?? 'none') !== 'none') {
            return true;
        }

        foreach ($input['lines'] as $line) {
            if (($line['line_discount']['type'] ?? 'none') !== 'none') {
                return true;
            }
        }

        return false;
    }

    private function resolveLine(int $tenantId, int $priceListId, int $warehouseId, array $line): array
    {
        $product = Product::withoutTenantScope()->where('tenant_id', $tenantId)->where('id', $line['product_id'])->first();
        if (! $product) {
            throw new ApiException('NOT_FOUND', 'Product not found.', 404);
        }

        $price = ProductPrice::withoutTenantScope()
            ->where('tenant_id', $tenantId)->where('product_id', $product->id)
            ->where('variant_key', $line['variant_id'] ?? 0)->where('price_list_id', $priceListId)
            ->where('unit_id', $line['unit_id'])->value('price');

        if ($price === null) {
            throw new ApiException('VALIDATION_FAILED', "No price is set for product #{$product->id} on this price list.", 422, ['product_id' => 'no price']);
        }

        $taxGroupKey = 'none';
        $taxGroupShape = ['mode' => 'exclusive', 'components' => []];
        if ($product->tax_group_id) {
            $taxGroup = TaxGroup::withoutTenantScope()->with('items.taxRate')->find($product->tax_group_id);
            $taxGroupKey = 'tg_'.$taxGroup->id;
            $taxGroupShape = [
                'mode' => $taxGroup->mode,
                'components' => $taxGroup->items->map(fn ($i) => ['code' => $i->taxRate->code, 'rate' => $i->taxRate->rate])->all(),
            ];
        }

        $balance = StockBalance::withoutTenantScope()
            ->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)
            ->where('product_id', $product->id)->where('variant_key', $line['variant_id'] ?? 0)->where('batch_key', 0)
            ->first();

        return [
            'product_id' => $product->id,
            'variant_id' => $line['variant_id'] ?? null,
            'unit_id' => $line['unit_id'],
            'quantity' => $line['quantity'],
            'unit_price' => $price,
            'price_source' => 'price_list',
            'line_discount' => $line['line_discount'] ?? null,
            'tax_group_key' => $taxGroupKey,
            'tax_group_shape' => $taxGroupShape,
            'cost_snapshot' => $balance === null ? '0.0000' : $balance->average_cost,
        ];
    }

    private function validatePayments(int $tenantId, array $payments, string $grandTotal, ?int $customerId): void
    {
        $owed = $grandTotal;
        foreach ($payments as $payment) {
            $method = PaymentMethod::withoutTenantScope()->where('tenant_id', $tenantId)->where('id', $payment['payment_method_id'])->first();
            if (! $method) {
                throw new ApiException('VALIDATION_FAILED', 'Unknown payment method.', 422);
            }

            if ($method->code === 'credit') {
                if (! $customerId) {
                    throw new ApiException('VALIDATION_FAILED', 'A customer is required for a credit payment.', 422);
                }
                $customer = Customer::withoutTenantScope()->find($customerId);
                if ($customer->credit_limit !== null) {
                    $newBalance = bcadd($this->customerLedger->currentBalance($tenantId, $customerId), $payment['amount'], 4);
                    if (bccomp($newBalance, $customer->credit_limit, 4) > 0) {
                        throw new ApiException('CREDIT_LIMIT_EXCEEDED', 'This sale would exceed the customer\'s credit limit.', 409);
                    }
                }
            }

            if (! $method->allows_overpayment && bccomp($payment['amount'], $owed, 4) > 0) {
                throw new ApiException('VALIDATION_FAILED', 'Payment amount exceeds the amount owed for a method that does not allow overpayment.', 422);
            }

            $owed = bcsub($owed, bccomp($payment['amount'], $owed, 4) > 0 ? $owed : $payment['amount'], 4);
        }

        if (bccomp($owed, '0.0000', 4) > 0) {
            throw new ApiException('VALIDATION_FAILED', "Payments do not cover the full amount - {$owed} remaining.", 422);
        }
    }

    private function applyPayments(int $tenantId, Sale $sale, array $payments, int $userId, array $input): void
    {
        $owed = $sale->grand_total;
        foreach ($payments as $payment) {
            $method = PaymentMethod::withoutTenantScope()->find($payment['payment_method_id']);
            $applied = bccomp($payment['amount'], $owed, 4) > 0 ? $owed : $payment['amount'];
            $change = bcsub($payment['amount'], $applied, 4);

            $salePayment = SalePayment::create([
                'sale_id' => $sale->id, 'payment_method_id' => $method->id, 'amount' => $payment['amount'], 'change_given' => $change,
            ]);

            PaymentTransaction::withoutTenantScope()->create([
                'tenant_id' => $tenantId, 'sale_payment_id' => $salePayment->id, 'status' => 'succeeded',
            ]);

            $owed = bcsub($owed, $applied, 4);

            if ($method->code === 'credit') {
                $this->customerLedger->recordEntry($tenantId, $sale->customer_id, 'debit', $applied, 'sale', $sale->id, null, $userId);
            } elseif ($method->code === 'cash' && ! empty($input['cashier_shift_id'])) {
                $this->shifts->recordCashMovement($tenantId, $input['cashier_shift_id'], 'cash_sale', bcsub($payment['amount'], $change, 4), null, $userId, 'sale', $sale->id);
            }
        }
    }
}
