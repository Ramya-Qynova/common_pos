<?php

namespace App\Services\Inventory\Costing;

use App\Models\CostLayer;
use App\Models\StockBalance;

class FifoCostingStrategy implements CostingStrategyInterface
{
    public function recordInflow(StockBalance $balance, string $quantity, string $unitCost, array $context = []): void
    {
        CostLayer::create([
            'tenant_id' => $balance->tenant_id,
            'warehouse_id' => $balance->warehouse_id,
            'product_id' => $balance->product_id,
            'variant_id' => $balance->variant_id,
            'batch_id' => $balance->batch_id,
            'quantity_remaining' => bcadd($quantity, '0', 4),
            'unit_cost' => bcadd($unitCost, '0', 4),
            'received_at' => $context['received_at'] ?? now(),
        ]);
    }

    public function calculateOutflowCost(StockBalance $balance, string $quantity, array $context = []): array
    {
        $layers = CostLayer::withoutTenantScope()
            ->where('tenant_id', $balance->tenant_id)
            ->where('warehouse_id', $balance->warehouse_id)
            ->where('product_id', $balance->product_id)
            ->when($balance->variant_id, fn ($q) => $q->where('variant_id', $balance->variant_id), fn ($q) => $q->whereNull('variant_id'))
            ->when($balance->batch_id, fn ($q) => $q->where('batch_id', $balance->batch_id), fn ($q) => $q->whereNull('batch_id'))
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        $needed = bcadd($quantity, '0', 4);
        $totalCost = '0.0000';
        $depletedLayers = [];
        $lastLayerCost = (string) $balance->average_cost;

        foreach ($layers as $layer) {
            if (bccomp($needed, '0.0000', 4) <= 0) {
                break;
            }

            $lastLayerCost = (string) $layer->unit_cost;
            $layerRemaining = (string) $layer->quantity_remaining;

            if (bccomp($layerRemaining, $needed, 4) <= 0) {
                // Deplete entire layer
                $take = $layerRemaining;
                $layer->quantity_remaining = '0.0000';
                $layer->save();
            } else {
                // Partially deplete layer
                $take = $needed;
                $layer->quantity_remaining = bcsub($layerRemaining, $take, 4);
                $layer->save();
            }

            $costPortion = bcmul($take, $layer->unit_cost, 4);
            $totalCost = bcadd($totalCost, $costPortion, 4);
            $needed = bcsub($needed, $take, 4);

            $depletedLayers[] = [
                'layer_id' => $layer->id,
                'quantity' => $take,
                'unit_cost' => (string) $layer->unit_cost,
                'cost_portion' => $costPortion,
            ];
        }

        // If not enough layers exist (e.g. negative stock permitted), cost the excess at the last known unit cost
        if (bccomp($needed, '0.0000', 4) > 0) {
            $excessCost = bcmul($needed, $lastLayerCost, 4);
            $totalCost = bcadd($totalCost, $excessCost, 4);
        }

        $effectiveUnitCost = bccomp($quantity, '0.0000', 4) > 0
            ? bcdiv($totalCost, $quantity, 4)
            : $lastLayerCost;

        return [
            'unit_cost' => $effectiveUnitCost,
            'total_cost' => $totalCost,
            'layers_depleted' => $depletedLayers,
        ];
    }

    public function getValuation(StockBalance $balance): string
    {
        $layers = CostLayer::withoutTenantScope()
            ->where('tenant_id', $balance->tenant_id)
            ->where('warehouse_id', $balance->warehouse_id)
            ->where('product_id', $balance->product_id)
            ->when($balance->variant_id, fn ($q) => $q->where('variant_id', $balance->variant_id), fn ($q) => $q->whereNull('variant_id'))
            ->when($balance->batch_id, fn ($q) => $q->where('batch_id', $balance->batch_id), fn ($q) => $q->whereNull('batch_id'))
            ->where('quantity_remaining', '>', 0)
            ->get();

        $valuation = '0.0000';
        foreach ($layers as $layer) {
            $layerVal = bcmul((string) $layer->quantity_remaining, (string) $layer->unit_cost, 4);
            $valuation = bcadd($valuation, $layerVal, 4);
        }

        return $valuation;
    }
}
