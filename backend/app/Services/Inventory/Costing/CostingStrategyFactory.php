<?php

namespace App\Services\Inventory\Costing;

use App\Services\Settings\SettingsService;

class CostingStrategyFactory
{
    public function __construct(private SettingsService $settings) {}

    public function forTenant(int $tenantId): CostingStrategyInterface
    {
        $method = (string) $this->settings->get('inventory.costing_method', $tenantId);

        if ($method === 'fifo') {
            return new FifoCostingStrategy;
        }

        return new WeightedAverageCostingStrategy;
    }
}
