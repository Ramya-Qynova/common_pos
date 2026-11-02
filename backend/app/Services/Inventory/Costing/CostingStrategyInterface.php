<?php

namespace App\Services\Inventory\Costing;

use App\Models\StockBalance;

interface CostingStrategyInterface
{
    /**
     * Record an incoming quantity and unit cost into the costing model.
     *
     * @param  array<string,mixed>  $context
     */
    public function recordInflow(StockBalance $balance, string $quantity, string $unitCost, array $context = []): void;

    /**
     * Compute the authoritative unit cost and total cost for an outgoing stock movement.
     *
     * @param  array<string,mixed>  $context
     * @return array{unit_cost: string, total_cost: string, layers_depleted?: array}
     */
    public function calculateOutflowCost(StockBalance $balance, string $quantity, array $context = []): array;

    /**
     * Compute total monetary inventory valuation for this balance row.
     */
    public function getValuation(StockBalance $balance): string;
}
