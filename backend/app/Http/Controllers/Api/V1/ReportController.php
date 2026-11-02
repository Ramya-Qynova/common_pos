<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Reports\ReportService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * section 8 "Reports (defined once)": one endpoint per figure, each a
 * thin wrapper over ReportService's single definition. `report` selects
 * which figure; `from`/`to` are required for the period-based ones.
 */
class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function show(Request $request)
    {
        $data = Validator::make($request->all(), [
            'report' => 'required|in:gross_sales,net_sales,tax_collected,cogs,gross_profit,stock_valuation,outstanding,expected_cash',
            'from' => 'required_without:shift_id|date',
            'to' => 'required_without:shift_id|date',
            'branch_id' => 'nullable|integer',
            'shift_id' => 'nullable|integer',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $branchId = $data['branch_id'] ?? null;

        $value = match ($data['report']) {
            'gross_sales' => $this->reports->grossSales($tenantId, $data['from'], $data['to'], $branchId),
            'net_sales' => $this->reports->netSales($tenantId, $data['from'], $data['to'], $branchId),
            'tax_collected' => $this->reports->taxCollected($tenantId, $data['from'], $data['to'], $branchId),
            'cogs' => $this->reports->cogs($tenantId, $data['from'], $data['to'], $branchId),
            'gross_profit' => $this->reports->grossProfit($tenantId, $data['from'], $data['to'], $branchId),
            'stock_valuation' => $this->reports->stockValuation($tenantId, $branchId),
            'outstanding' => $this->reports->outstanding($tenantId),
            'expected_cash' => $this->reports->expectedCash($tenantId, $data['shift_id'] ?? throw new ApiException('VALIDATION_FAILED', 'shift_id is required for expected_cash.', 422)),
            default => throw new ApiException('VALIDATION_FAILED', 'Unknown report.', 422),
        };

        return ApiResponse::success(['report' => $data['report'], 'value' => $value]);
    }

    public function summary(Request $request)
    {
        $data = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'branch_id' => 'nullable|integer',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $branchId = $data['branch_id'] ?? null;
        $from = $data['from'] ?? now()->startOfDay()->toDateTimeString();
        $to = $data['to'] ?? now()->endOfDay()->toDateTimeString();

        return ApiResponse::success([
            'from' => $from,
            'to' => $to,
            'gross_sales' => $this->reports->grossSales($tenantId, $from, $to, $branchId),
            'net_sales' => $this->reports->netSales($tenantId, $from, $to, $branchId),
            'tax_collected' => $this->reports->taxCollected($tenantId, $from, $to, $branchId),
            'cogs' => $this->reports->cogs($tenantId, $from, $to, $branchId),
            'gross_profit' => $this->reports->grossProfit($tenantId, $from, $to, $branchId),
            'stock_valuation' => $this->reports->stockValuation($tenantId, $branchId),
            'outstanding' => $this->reports->outstanding($tenantId),
        ]);
    }

    public function profitLoss(Request $request)
    {
        $data = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'branch_id' => 'nullable|integer',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $branchId = $data['branch_id'] ?? null;
        $from = $data['from'] ?? now()->startOfDay()->toDateTimeString();
        $to = $data['to'] ?? now()->endOfDay()->toDateTimeString();

        return ApiResponse::success($this->reports->profitLoss($tenantId, $from, $to, $branchId));
    }

    public function taxBreakdown(Request $request)
    {
        $data = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'branch_id' => 'nullable|integer',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $branchId = $data['branch_id'] ?? null;
        $from = $data['from'] ?? now()->startOfDay()->toDateTimeString();
        $to = $data['to'] ?? now()->endOfDay()->toDateTimeString();

        return ApiResponse::success($this->reports->taxBreakdown($tenantId, $from, $to, $branchId));
    }

    public function inventoryValuation(Request $request)
    {
        $data = Validator::make($request->all(), [
            'branch_id' => 'nullable|integer',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $branchId = $data['branch_id'] ?? null;

        return ApiResponse::success($this->reports->inventoryValuationDetail($tenantId, $branchId));
    }

    public function cashReconciliation(Request $request)
    {
        $data = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'branch_id' => 'nullable|integer',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $branchId = $data['branch_id'] ?? null;
        $from = $data['from'] ?? now()->startOfDay()->toDateTimeString();
        $to = $data['to'] ?? now()->endOfDay()->toDateTimeString();

        return ApiResponse::success($this->reports->cashReconciliation($tenantId, $from, $to, $branchId));
    }
}
