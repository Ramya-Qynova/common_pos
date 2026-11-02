<?php

namespace App\Services\Integrations;

use App\Models\CustomerLedger;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\SupplierLedger;

class GenericCsvAccountingExporter implements AccountingExporter
{
    public function exportSalesJournal(int $tenantId, string $from, string $to, ?int $branchId = null): string
    {
        $sales = Sale::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->where('is_test', false)
            ->whereBetween('server_created_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['branch', 'customer', 'invoice'])
            ->orderBy('server_created_at')
            ->get();

        $output = fopen('php://temp', 'r+');

        fputcsv($output, [
            'Invoice_Number',
            'Date',
            'Branch',
            'Customer',
            'Subtotal',
            'Discount',
            'Tax',
            'Grand_Total',
            'Status',
        ]);

        foreach ($sales as $sale) {
            fputcsv($output, [
                $sale->invoice?->full_number ?? "SALE-{$sale->id}",
                $sale->server_created_at?->toIso8601String(),
                $sale->branch?->name ?? 'Default Branch',
                $sale->customer?->name ?? 'Walk-in',
                bcadd((string) $sale->line_subtotal, '0.0000', 4),
                bcadd((string) $sale->discount_total, '0.0000', 4),
                bcadd((string) $sale->tax_total, '0.0000', 4),
                bcadd((string) $sale->grand_total, '0.0000', 4),
                $sale->status,
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }

    public function exportGeneralLedger(int $tenantId, string $from, string $to): string
    {
        $customerEntries = CustomerLedger::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereBetween('occurred_at', [$from, $to])
            ->with('customer')
            ->orderBy('occurred_at')
            ->get();

        $supplierEntries = SupplierLedger::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereBetween('occurred_at', [$from, $to])
            ->with('supplier')
            ->orderBy('occurred_at')
            ->get();

        $output = fopen('php://temp', 'r+');

        fputcsv($output, [
            'Date',
            'Ledger_Type',
            'Party_Code',
            'Party_Name',
            'Source_Type',
            'Direction',
            'Amount',
            'Balance_After',
            'Description',
        ]);

        foreach ($customerEntries as $entry) {
            fputcsv($output, [
                $entry->occurred_at?->toIso8601String(),
                'Customer',
                $entry->customer?->code ?? '',
                $entry->customer?->name ?? 'Customer',
                $entry->source_type,
                $entry->direction,
                bcadd((string) $entry->amount, '0.0000', 4),
                bcadd((string) $entry->balance_after, '0.0000', 4),
                $entry->reason ?? '',
            ]);
        }

        foreach ($supplierEntries as $entry) {
            fputcsv($output, [
                $entry->occurred_at?->toIso8601String(),
                'Supplier',
                $entry->supplier?->code ?? '',
                $entry->supplier?->name ?? 'Supplier',
                $entry->source_type,
                $entry->direction,
                bcadd((string) $entry->amount, '0.0000', 4),
                bcadd((string) $entry->balance_after, '0.0000', 4),
                $entry->reason ?? '',
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }

    public function exportInventoryValuation(int $tenantId, ?int $warehouseId = null): string
    {
        $query = StockBalance::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with(['product.category', 'warehouse']);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $balances = $query->get();

        $output = fopen('php://temp', 'r+');

        fputcsv($output, [
            'Warehouse',
            'SKU',
            'Product_Name',
            'Category',
            'Quantity',
            'Average_Cost',
            'Valuation',
        ]);

        foreach ($balances as $bal) {
            $val = bcmul((string) $bal->quantity, (string) $bal->average_cost, 4);
            fputcsv($output, [
                $bal->warehouse?->name ?? 'Default Warehouse',
                $bal->product?->sku ?? '',
                $bal->product?->name ?? '',
                $bal->product?->category?->name ?? 'Uncategorized',
                $bal->quantity,
                $bal->average_cost,
                $val,
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }
}
