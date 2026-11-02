<?php

namespace App\Services\Reports;

use App\Models\CashierShift;
use App\Models\CustomerLedger;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemTax;
use App\Models\SaleReturn;
use App\Models\StockBalance;
use App\Models\SupplierLedger;
use App\Models\Warehouse;
use App\Services\Finance\ShiftService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * section 8 "Reports (defined once)": each figure below is the single
 * definition every dashboard/export must call - never recomputed ad hoc
 * elsewhere. "Reports read committed records only" - every query here
 * reads already-committed rows, nothing is computed inside an open
 * transaction. Test sales (is_test) are excluded throughout, matching the
 * onboarding go-live job that reverses and excludes them (section 8
 * "Onboarding"). $from/$to are caller-resolved timestamps (business-day
 * boundary handling per locale.business_day_cutoff is the caller's job).
 */
class ReportService
{
    public function __construct(private ShiftService $shifts) {}

    public function grossSales(int $tenantId, string $from, string $to, ?int $branchId = null): string
    {
        $saleIds = $this->completedSaleIds($tenantId, $from, $to, $branchId);

        $total = '0.0000';
        foreach (SaleItem::whereIn('sale_id', $saleIds)->get(['quantity', 'unit_price']) as $item) {
            $total = bcadd($total, bcmul($item->quantity, $item->unit_price, 4), 4);
        }

        return $total;
    }

    public function netSales(int $tenantId, string $from, string $to, ?int $branchId = null): string
    {
        $gross = $this->grossSales($tenantId, $from, $to, $branchId);
        $discounts = $this->discountTotal($tenantId, $from, $to, $branchId);
        $returned = $this->returnedAmounts($tenantId, $from, $to, $branchId);

        return bcsub(bcsub($gross, $discounts, 4), $returned['taxable'], 4);
    }

    public function taxCollected(int $tenantId, string $from, string $to, ?int $branchId = null): string
    {
        $saleIds = $this->completedSaleIds($tenantId, $from, $to, $branchId);
        $saleItemIds = SaleItem::whereIn('sale_id', $saleIds)->pluck('id');

        $total = (string) SaleItemTax::whereIn('sale_item_id', $saleItemIds)->sum('amount');
        $total = bcadd($total, '0.0000', 4);

        return bcsub($total, $this->returnedAmounts($tenantId, $from, $to, $branchId)['tax'], 4);
    }

    public function cogs(int $tenantId, string $from, string $to, ?int $branchId = null): string
    {
        $saleIds = $this->completedSaleIds($tenantId, $from, $to, $branchId);

        $total = '0.0000';
        foreach (SaleItem::whereIn('sale_id', $saleIds)->get(['quantity', 'cost_snapshot']) as $item) {
            $total = bcadd($total, bcmul($item->quantity, $item->cost_snapshot, 4), 4);
        }

        return bcsub($total, $this->returnedAmounts($tenantId, $from, $to, $branchId)['cogs'], 4);
    }

    public function grossProfit(int $tenantId, string $from, string $to, ?int $branchId = null): string
    {
        return bcsub($this->netSales($tenantId, $from, $to, $branchId), $this->cogs($tenantId, $from, $to, $branchId), 4);
    }

    public function stockValuation(int $tenantId, ?int $branchId = null): string
    {
        $warehouseIds = $branchId === null
            ? Warehouse::withoutTenantScope()->where('tenant_id', $tenantId)->pluck('id')
            : Warehouse::withoutTenantScope()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->pluck('id');

        $total = '0.0000';
        foreach (StockBalance::withoutTenantScope()->whereIn('warehouse_id', $warehouseIds)->get(['quantity', 'average_cost']) as $balance) {
            $total = bcadd($total, bcmul($balance->quantity, $balance->average_cost, 4), 4);
        }

        return $total;
    }

    /**
     * @return array{customer:string,supplier:string}
     */
    public function outstanding(int $tenantId): array
    {
        $latestCustomerIds = CustomerLedger::withoutTenantScope()->where('tenant_id', $tenantId)
            ->selectRaw('MAX(id) as id')->groupBy('customer_id')->pluck('id');
        $customerTotal = $this->sumBalanceAfter(CustomerLedger::whereIn('id', $latestCustomerIds)->get(['balance_after']));

        $latestSupplierIds = SupplierLedger::withoutTenantScope()->where('tenant_id', $tenantId)
            ->selectRaw('MAX(id) as id')->groupBy('supplier_id')->pluck('id');
        $supplierTotal = $this->sumBalanceAfter(SupplierLedger::whereIn('id', $latestSupplierIds)->get(['balance_after']));

        return ['customer' => $customerTotal, 'supplier' => $supplierTotal];
    }

    public function expectedCash(int $tenantId, int $shiftId): string
    {
        return $this->shifts->expectedCash($tenantId, $shiftId);
    }

    private function sumBalanceAfter(Collection $rows): string
    {
        $total = '0.0000';
        foreach ($rows as $row) {
            $total = bcadd($total, $row->balance_after, 4);
        }

        return $total;
    }

    private function completedSaleIds(int $tenantId, string $from, string $to, ?int $branchId)
    {
        return Sale::withoutTenantScope()
            ->where('tenant_id', $tenantId)->where('status', 'completed')->where('is_test', false)
            ->whereBetween('server_created_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->pluck('id');
    }

    private function discountTotal(int $tenantId, string $from, string $to, ?int $branchId): string
    {
        $total = (string) Sale::withoutTenantScope()
            ->where('tenant_id', $tenantId)->where('status', 'completed')->where('is_test', false)
            ->whereBetween('server_created_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('discount_total');

        return bcadd($total, '0.0000', 4);
    }

    /**
     * Returns made in [from, to] - recognized in the period the return
     * happened, not the period of the original sale (section 8 "Net
     * sales ... − returns" is a period-of-return figure, matching normal
     * accounting treatment).
     *
     * @return array{taxable:string,tax:string,cogs:string}
     */
    private function returnedAmounts(int $tenantId, string $from, string $to, ?int $branchId): array
    {
        $returnIds = SaleReturn::withoutTenantScope()
            ->where('tenant_id', $tenantId)->where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->whereIn('sale_id', Sale::withoutTenantScope()->where('branch_id', $branchId)->pluck('id')))
            ->pluck('id');

        $rows = DB::table('sale_return_items')
            ->join('sale_items', 'sale_items.id', '=', 'sale_return_items.sale_item_id')
            ->whereIn('sale_return_items.sale_return_id', $returnIds)
            ->get([
                'sale_return_items.quantity as return_quantity', 'sale_return_items.condition as condition',
                'sale_items.quantity as sold_quantity', 'sale_items.taxable_amount', 'sale_items.tax_amount', 'sale_items.cost_snapshot',
            ]);

        $taxable = '0.0000';
        $tax = '0.0000';
        $cogs = '0.0000';

        foreach ($rows as $row) {
            // Multiply before dividing (see SaleReturnService::complete() for why) - avoids
            // compounding two truncations on an exact-division case like 1/3.
            if (bccomp($row->sold_quantity, '0.0000', 4) > 0) {
                $taxable = bcadd($taxable, bcdiv(bcmul($row->return_quantity, $row->taxable_amount, 8), $row->sold_quantity, 4), 4);
                $tax = bcadd($tax, bcdiv(bcmul($row->return_quantity, $row->tax_amount, 8), $row->sold_quantity, 4), 4);
            }

            if ($row->condition === 'resellable') {
                $cogs = bcadd($cogs, bcmul($row->return_quantity, $row->cost_snapshot, 4), 4);
            }
        }

        return ['taxable' => $taxable, 'tax' => $tax, 'cogs' => $cogs];
    }

    public function profitLoss(int $tenantId, string $from, string $to, ?int $branchId = null): array
    {
        $grossSales = $this->grossSales($tenantId, $from, $to, $branchId);
        $discounts = $this->discountTotal($tenantId, $from, $to, $branchId);
        $returns = $this->returnedAmounts($tenantId, $from, $to, $branchId);
        $netSales = bcsub(bcsub($grossSales, $discounts, 4), $returns['taxable'], 4);
        $cogs = $this->cogs($tenantId, $from, $to, $branchId);
        $grossProfit = bcsub($netSales, $cogs, 4);

        $expenseQuery = Expense::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereBetween('paid_at', [$from, $to]);

        if ($branchId !== null) {
            $expenseQuery->whereHas('shift.register', fn ($q) => $q->where('branch_id', $branchId));
        }

        $operatingExpenses = bcadd((string) $expenseQuery->sum('amount'), '0.0000', 4);
        $netProfit = bcsub($grossProfit, $operatingExpenses, 4);

        return [
            'from' => $from,
            'to' => $to,
            'gross_sales' => $grossSales,
            'discounts' => $discounts,
            'returns' => $returns['taxable'],
            'net_sales' => $netSales,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $operatingExpenses,
            'net_profit' => $netProfit,
        ];
    }

    public function taxBreakdown(int $tenantId, string $from, string $to, ?int $branchId = null): array
    {
        $saleIds = $this->completedSaleIds($tenantId, $from, $to, $branchId);
        $saleItems = SaleItem::whereIn('sale_id', $saleIds)->get(['id', 'taxable_amount', 'tax_amount', 'quantity']);
        $saleItemMap = $saleItems->keyBy('id');
        $saleItemIds = $saleItems->pluck('id');

        $taxes = SaleItemTax::whereIn('sale_item_id', $saleItemIds)->get();

        $collectedByCode = [];
        $taxableByCode = [];
        foreach ($taxes as $tax) {
            $code = $tax->tax_code;
            if (! isset($collectedByCode[$code])) {
                $collectedByCode[$code] = '0.0000';
                $taxableByCode[$code] = '0.0000';
            }
            $collectedByCode[$code] = bcadd($collectedByCode[$code], (string) $tax->amount, 4);
            $item = $saleItemMap->get($tax->sale_item_id);
            if ($item) {
                $taxableByCode[$code] = bcadd($taxableByCode[$code], (string) $item->taxable_amount, 4);
            }
        }

        $returnIds = SaleReturn::withoutTenantScope()
            ->where('tenant_id', $tenantId)->where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->whereIn('sale_id', Sale::withoutTenantScope()->where('branch_id', $branchId)->pluck('id')))
            ->pluck('id');

        $refundRows = DB::table('sale_return_items')
            ->join('sale_items', 'sale_items.id', '=', 'sale_return_items.sale_item_id')
            ->whereIn('sale_return_items.sale_return_id', $returnIds)
            ->get([
                'sale_return_items.sale_item_id',
                'sale_return_items.quantity as return_quantity',
                'sale_items.quantity as sold_quantity',
            ]);

        $refundedByCode = [];
        foreach ($refundRows as $rf) {
            if (bccomp((string) $rf->sold_quantity, '0.0000', 4) <= 0) {
                continue;
            }
            $origTaxes = SaleItemTax::where('sale_item_id', $rf->sale_item_id)->get();
            foreach ($origTaxes as $origTax) {
                $code = $origTax->tax_code;
                if (! isset($refundedByCode[$code])) {
                    $refundedByCode[$code] = '0.0000';
                }
                $lineRefundedTax = bcdiv(bcmul((string) $rf->return_quantity, (string) $origTax->amount, 8), (string) $rf->sold_quantity, 4);
                $refundedByCode[$code] = bcadd($refundedByCode[$code], $lineRefundedTax, 4);
            }
        }

        $allCodes = array_unique(array_merge(array_keys($collectedByCode), array_keys($refundedByCode)));
        sort($allCodes);

        $components = [];
        $totalTaxable = '0.0000';
        $totalCollected = '0.0000';
        $totalRefunded = '0.0000';
        $totalNet = '0.0000';

        foreach ($allCodes as $code) {
            $taxable = $taxableByCode[$code] ?? '0.0000';
            $collected = $collectedByCode[$code] ?? '0.0000';
            $refunded = $refundedByCode[$code] ?? '0.0000';
            $net = bcsub($collected, $refunded, 4);

            $components[] = [
                'tax_code' => $code,
                'taxable_amount' => $taxable,
                'collected_amount' => $collected,
                'refunded_amount' => $refunded,
                'net_amount' => $net,
            ];

            $totalTaxable = bcadd($totalTaxable, $taxable, 4);
            $totalCollected = bcadd($totalCollected, $collected, 4);
            $totalRefunded = bcadd($totalRefunded, $refunded, 4);
            $totalNet = bcadd($totalNet, $net, 4);
        }

        return [
            'from' => $from,
            'to' => $to,
            'components' => $components,
            'totals' => [
                'taxable_amount' => $totalTaxable,
                'collected_amount' => $totalCollected,
                'refunded_amount' => $totalRefunded,
                'net_amount' => $totalNet,
            ],
        ];
    }

    public function inventoryValuationDetail(int $tenantId, ?int $branchId = null): array
    {
        $warehouseIds = $branchId === null
            ? Warehouse::withoutTenantScope()->where('tenant_id', $tenantId)->pluck('id')
            : Warehouse::withoutTenantScope()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->pluck('id');

        $balances = StockBalance::withoutTenantScope()
            ->whereIn('warehouse_id', $warehouseIds)
            ->with(['product.category', 'warehouse'])
            ->get();

        $byWarehouse = [];
        $byCategory = [];
        $totalUnits = '0.0000';
        $totalValuation = '0.0000';
        $lowStockCount = 0;
        $skuSet = [];

        foreach ($balances as $bal) {
            $qty = $bal->quantity;
            $cost = $bal->average_cost;
            $lineVal = bcmul($qty, $cost, 4);

            $totalUnits = bcadd($totalUnits, $qty, 4);
            $totalValuation = bcadd($totalValuation, $lineVal, 4);

            if ($bal->reorder_point !== null && bccomp($qty, $bal->reorder_point, 4) <= 0) {
                $lowStockCount++;
            }

            $skuKey = $bal->product_id.'-'.($bal->variant_id ?? '0');
            $skuSet[$skuKey] = true;

            $whId = $bal->warehouse_id;
            $whName = $bal->warehouse?->name ?? "Warehouse #{$whId}";
            if (! isset($byWarehouse[$whId])) {
                $byWarehouse[$whId] = [
                    'warehouse_id' => $whId,
                    'warehouse_name' => $whName,
                    'sku_count' => 0,
                    'total_units' => '0.0000',
                    'total_valuation' => '0.0000',
                    'skus' => [],
                ];
            }
            $byWarehouse[$whId]['total_units'] = bcadd($byWarehouse[$whId]['total_units'], $qty, 4);
            $byWarehouse[$whId]['total_valuation'] = bcadd($byWarehouse[$whId]['total_valuation'], $lineVal, 4);
            $byWarehouse[$whId]['skus'][$skuKey] = true;

            $catId = $bal->product?->category_id ?? 0;
            $catName = $bal->product?->category?->name ?? 'Uncategorized';
            if (! isset($byCategory[$catId])) {
                $byCategory[$catId] = [
                    'category_id' => $catId,
                    'category_name' => $catName,
                    'sku_count' => 0,
                    'total_units' => '0.0000',
                    'total_valuation' => '0.0000',
                    'skus' => [],
                ];
            }
            $byCategory[$catId]['total_units'] = bcadd($byCategory[$catId]['total_units'], $qty, 4);
            $byCategory[$catId]['total_valuation'] = bcadd($byCategory[$catId]['total_valuation'], $lineVal, 4);
            $byCategory[$catId]['skus'][$skuKey] = true;
        }

        $whList = array_values(array_map(function ($item) {
            $item['sku_count'] = count($item['skus']);
            unset($item['skus']);

            return $item;
        }, $byWarehouse));

        $catList = array_values(array_map(function ($item) {
            $item['sku_count'] = count($item['skus']);
            unset($item['skus']);

            return $item;
        }, $byCategory));

        return [
            'summary' => [
                'total_skus' => count($skuSet),
                'total_quantity' => $totalUnits,
                'total_valuation' => $totalValuation,
                'low_stock_count' => $lowStockCount,
            ],
            'by_warehouse' => $whList,
            'by_category' => $catList,
        ];
    }

    public function cashReconciliation(int $tenantId, string $from, string $to, ?int $branchId = null): array
    {
        $shiftsQuery = CashierShift::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$from, $to])
            ->with(['register.branch', 'openedBy', 'closedBy', 'movements']);

        if ($branchId !== null) {
            $shiftsQuery->whereHas('register', fn ($q) => $q->where('branch_id', $branchId));
        }

        $shifts = $shiftsQuery->orderBy('closed_at', 'desc')->get();

        $shiftList = [];
        $totalOpening = '0.0000';
        $totalCashSales = '0.0000';
        $totalCashRefunds = '0.0000';
        $totalExpenses = '0.0000';
        $totalCashIn = '0.0000';
        $totalCashOut = '0.0000';
        $totalExpected = '0.0000';
        $totalCounted = '0.0000';
        $totalDifference = '0.0000';

        foreach ($shifts as $shift) {
            $cashSales = '0.0000';
            $cashRefunds = '0.0000';
            $expenses = '0.0000';
            $cashIn = '0.0000';
            $cashOut = '0.0000';

            foreach ($shift->movements as $m) {
                match ($m->type) {
                    'cash_sale' => $cashSales = bcadd($cashSales, (string) $m->amount, 4),
                    'cash_refund' => $cashRefunds = bcadd($cashRefunds, (string) $m->amount, 4),
                    'expense' => $expenses = bcadd($expenses, (string) $m->amount, 4),
                    'cash_in' => $cashIn = bcadd($cashIn, (string) $m->amount, 4),
                    'cash_out' => $cashOut = bcadd($cashOut, (string) $m->amount, 4),
                    default => null,
                };
            }

            $opening = bcadd((string) $shift->opening_cash, '0.0000', 4);
            $expected = bcadd((string) ($shift->expected_cash ?? '0.0000'), '0.0000', 4);
            $counted = bcadd((string) ($shift->closing_cash_counted ?? '0.0000'), '0.0000', 4);
            $diff = bcadd((string) ($shift->cash_difference ?? '0.0000'), '0.0000', 4);

            $shiftList[] = [
                'id' => $shift->id,
                'uuid' => $shift->uuid,
                'register_id' => $shift->register_id,
                'register_name' => $shift->register?->name ?? "Register #{$shift->register_id}",
                'branch_id' => $shift->register?->branch_id,
                'branch_name' => $shift->register?->branch?->name ?? 'Default Branch',
                'opened_by_name' => $shift->openedBy?->name ?? 'Unknown',
                'closed_by_name' => $shift->closedBy?->name ?? 'Unknown',
                'opened_at' => $shift->opened_at?->toIso8601String(),
                'closed_at' => $shift->closed_at?->toIso8601String(),
                'opening_cash' => $opening,
                'cash_sales' => $cashSales,
                'cash_refunds' => $cashRefunds,
                'expenses' => $expenses,
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'cash_difference' => $diff,
            ];

            $totalOpening = bcadd($totalOpening, $opening, 4);
            $totalCashSales = bcadd($totalCashSales, $cashSales, 4);
            $totalCashRefunds = bcadd($totalCashRefunds, $cashRefunds, 4);
            $totalExpenses = bcadd($totalExpenses, $expenses, 4);
            $totalCashIn = bcadd($totalCashIn, $cashIn, 4);
            $totalCashOut = bcadd($totalCashOut, $cashOut, 4);
            $totalExpected = bcadd($totalExpected, $expected, 4);
            $totalCounted = bcadd($totalCounted, $counted, 4);
            $totalDifference = bcadd($totalDifference, $diff, 4);
        }

        return [
            'from' => $from,
            'to' => $to,
            'shifts' => $shiftList,
            'totals' => [
                'shift_count' => count($shiftList),
                'opening_cash' => $totalOpening,
                'cash_sales' => $totalCashSales,
                'cash_refunds' => $totalCashRefunds,
                'expenses' => $totalExpenses,
                'cash_in' => $totalCashIn,
                'cash_out' => $totalCashOut,
                'expected_cash' => $totalExpected,
                'counted_cash' => $totalCounted,
                'cash_difference' => $totalDifference,
            ],
        ];
    }
}
