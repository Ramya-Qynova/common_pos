<?php

namespace App\Services\Integrations;

/**
 * section 12 "Adapter interfaces (no vendor hardcoded): AccountingExporter (generic CSV first)".
 */
interface AccountingExporter
{
    /**
     * Exports completed sales transactions into standard sales journal format.
     */
    public function exportSalesJournal(int $tenantId, string $from, string $to, ?int $branchId = null): string;

    /**
     * Exports general ledger / customer & supplier transactions for accounting reconciliation.
     */
    public function exportGeneralLedger(int $tenantId, string $from, string $to): string;

    /**
     * Exports current inventory valuation journal.
     */
    public function exportInventoryValuation(int $tenantId, ?int $warehouseId = null): string;
}
