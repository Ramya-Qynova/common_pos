<?php

namespace App\Services\Inventory\Costing;

use App\Models\StockBalance;

class WeightedAverageCostingStrategy implements CostingStrategyInterface
{
    public function recordInflow(StockBalance $balance, string $quantity, string $unitCost, array $context = []): void
    {
        // Handled directly via (current_value + incoming_value) / new_quantity on the balance
    }

    public function calculateOutflowCost(StockBalance $balance, string $quantity, array $context = []): array
    {
        $unitCost = (string) $balance->average_cost;
        $totalCost = bcmul($quantity, $unitCost, 4);

        return [
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
        ];
    }

    public function getValuation(StockBalance $balance): string
    {
        return bcmul((string) $balance->quantity, (string) $balance->average_cost, 4);
    }
}
