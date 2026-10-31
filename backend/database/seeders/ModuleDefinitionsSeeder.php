<?php

namespace Database\Seeders;

use App\Models\ModuleDefinition;
use Illuminate\Database\Seeder;

/**
 * section 6 "Modules". `menu` entries are what GET /tenant/config assembles
 * into the client's menu (section 11) - a disabled module's menu entries
 * simply aren't in the enabled-module set TenantConfigController reads
 * from, so they vanish with no frontend code change.
 */
class ModuleDefinitionsSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            ['core', 'Core', [], [], [], true],
            ['catalog', 'Catalogue', ['core'], ['catalog.products.manage'], [['code' => 'catalog', 'label_key' => 'menu.catalog', 'path' => '/catalog']], false],
            ['inventory', 'Inventory', ['catalog'], ['inventory.adjust', 'inventory.transfer', 'inventory.view'], [['code' => 'inventory', 'label_key' => 'menu.inventory', 'path' => '/inventory']], false],
            ['pos', 'Point of Sale', ['catalog', 'inventory'], ['pos.sales.create', 'pos.sales.discount', 'pos.shift.open', 'pos.shift.close'], [['code' => 'pos', 'label_key' => 'menu.pos', 'path' => '/pos']], false],
            ['sales_mgmt', 'Sales Management', ['pos'], ['pos.sales.cancel', 'pos.sales.return'], [['code' => 'sales_mgmt', 'label_key' => 'menu.sales', 'path' => '/sales']], false],
            ['purchases', 'Purchases', ['catalog', 'inventory'], ['purchases.manage'], [['code' => 'purchases', 'label_key' => 'menu.purchases', 'path' => '/purchases']], false],
            ['customers', 'Customers', ['core'], ['customers.manage'], [['code' => 'customers', 'label_key' => 'menu.customers', 'path' => '/customers']], false],
            ['finance', 'Finance', ['core'], ['finance.expenses.manage', 'finance.daily_close', 'finance.reopen'], [['code' => 'finance', 'label_key' => 'menu.finance', 'path' => '/finance']], false],
            ['reports', 'Reports', ['core'], ['reports.view'], [['code' => 'reports', 'label_key' => 'menu.reports', 'path' => '/reports']], false],
            ['offline', 'Offline sync', ['pos'], [], [], false],
            ['batch_expiry', 'Batch & expiry tracking', ['inventory'], [], [], false],
            ['serial_tracking', 'Serial number tracking', ['inventory'], [], [], false],
            ['variants', 'Product variants', ['catalog'], [], [], false],
            ['weighing', 'Weighing scale integration', ['pos'], [], [], false],
            ['restaurant', 'Restaurant / table service', ['pos'], [], [], false],
            ['price_lists_advanced', 'Advanced price lists', ['catalog'], [], [], false],
            ['notifications', 'Notifications', ['core'], [], [], false],
            ['integrations', 'Integrations', ['core'], [], [], false],
        ];

        foreach ($modules as [$code, $name, $deps, $perms, $menu, $isCore]) {
            ModuleDefinition::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'dependencies' => $deps, 'permissions' => $perms, 'menu' => $menu, 'is_core' => $isCore],
            );
        }
    }
}
