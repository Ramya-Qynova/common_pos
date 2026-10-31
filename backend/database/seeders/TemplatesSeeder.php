<?php

namespace Database\Seeders;

use App\Models\Template;
use App\Models\TemplateVersion;
use Illuminate\Database\Seeder;

/**
 * section 6 "Templates": a versioned data bundle, copied (not referenced
 * live) into a tenant at onboarding (section 8). This is the first
 * template - "General Retail" - a generic starting point; the
 * Vegetable/Grocery template (section 1.2 "First template") is added in
 * Phase 6 on top of this same generic engine, as data, not code.
 */
class TemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $template = Template::updateOrCreate(
            ['code' => 'general_retail'],
            ['name' => 'General Retail', 'description' => 'Generic starting point for any shop type.', 'is_active' => true],
        );

        TemplateVersion::updateOrCreate(
            ['template_id' => $template->id, 'version' => 1],
            [
                'is_published' => true,
                'published_at' => now(),
                'data' => [
                    'modules' => ['core', 'catalog', 'inventory', 'pos', 'sales_mgmt', 'customers', 'finance', 'reports'],
                    'default_roles' => [
                        ['code' => 'owner', 'name' => 'Owner', 'is_system' => true, 'permissions' => ['*']],
                        ['code' => 'admin', 'name' => 'Admin', 'is_system' => true, 'permissions' => [
                            'core.users.manage', 'core.branches.manage', 'core.audit.view',
                            'catalog.products.manage', 'inventory.adjust', 'inventory.transfer', 'inventory.view',
                            'pos.sales.create', 'pos.sales.discount', 'pos.sales.cancel', 'pos.sales.return',
                            'pos.shift.open', 'pos.shift.close', 'customers.manage',
                            'finance.expenses.manage', 'finance.daily_close', 'reports.view', 'approvals.approve',
                        ]],
                        ['code' => 'cashier', 'name' => 'Cashier', 'is_system' => true, 'permissions' => [
                            'pos.sales.create', 'pos.shift.open', 'pos.shift.close', 'inventory.view',
                        ]],
                    ],
                    'default_settings' => [],
                    'units' => ['pcs', 'kg'],
                    'categories' => [],
                    'lookups' => [],
                    'receipt_layouts' => ['default' => ['sections' => ['header', 'lines', 'totals', 'footer']]],
                    'ui_hints' => [],
                ],
            ],
        );
    }
}
