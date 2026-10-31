<?php

namespace App\Services\Reports;

use App\Models\CustomerLedger;
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
}
