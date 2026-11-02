<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Integrations\AccountingExporter;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AccountingExportController extends Controller
{
    public function __construct(private AccountingExporter $exporter) {}

    public function sales(Request $request)
    {
        $data = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'branch_id' => 'nullable|integer',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $from = $data['from'] ?? now()->startOfMonth()->toDateTimeString();
        $to = $data['to'] ?? now()->endOfDay()->toDateTimeString();
        $branchId = $data['branch_id'] ?? null;

        $csv = $this->exporter->exportSalesJournal($tenantId, $from, $to, $branchId);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="sales-journal-'.now()->format('Ymd-His').'.csv"',
        ]);
    }

    public function ledger(Request $request)
    {
        $data = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $from = $data['from'] ?? now()->startOfMonth()->toDateTimeString();
        $to = $data['to'] ?? now()->endOfDay()->toDateTimeString();

        $csv = $this->exporter->exportGeneralLedger($tenantId, $from, $to);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="general-ledger-'.now()->format('Ymd-His').'.csv"',
        ]);
    }

    public function inventory(Request $request)
    {
        $data = Validator::make($request->all(), [
            'warehouse_id' => 'nullable|integer',
        ])->validate();

        $tenantId = app(TenantContext::class)->tenantId();
        $warehouseId = $data['warehouse_id'] ?? null;

        $csv = $this->exporter->exportInventoryValuation($tenantId, $warehouseId);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="inventory-valuation-'.now()->format('Ymd-His').'.csv"',
        ]);
    }
}
