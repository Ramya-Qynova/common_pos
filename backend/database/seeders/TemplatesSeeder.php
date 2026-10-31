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
                    'units' => [['code' => 'pcs', 'name' => 'Pieces'], ['code' => 'kg', 'name' => 'Kilogram']],
                    'categories' => [],
                    'lookups' => [],
                    'receipt_layouts' => ['default' => ['sections' => ['header', 'lines', 'totals', 'footer']]],
                    'ui_hints' => [],
                ],
            ],
        );

        // section 1.2/12 Phase 6 "First template: Vegetable/Grocery" - built on the exact
        // same generic template engine as general_retail above, as DATA only. Nothing in
        // application code branches on this template's code or name (section 4's Dynamic-
        // Only Rule) - weighing, variable-weight barcodes, and the extra wastage reasons
        // below all come from the `weighing` module flag + this data, read by the same
        // TemplateUpdateService/OnboardingService/BarcodeResolutionService any template uses.
        $grocery = Template::updateOrCreate(
            ['code' => 'grocery'],
            ['name' => 'Vegetable / Grocery', 'description' => 'Weighed produce, daily rate changes, high wastage.', 'is_active' => true],
        );

        TemplateVersion::updateOrCreate(
            ['template_id' => $grocery->id, 'version' => 1],
            [
                'is_published' => true,
                'published_at' => now(),
                'data' => [
                    'modules' => ['core', 'catalog', 'inventory', 'pos', 'sales_mgmt', 'purchases', 'customers', 'finance', 'reports', 'weighing', 'batch_expiry'],
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
                    // Overrides the platform default (disabled) - a grocery counter weighs produce and
                    // scans the scale's own printed barcode instead of typing a quantity. Still just a
                    // tenant-level settings override (section 4 "Settings resolution"), not a code branch.
                    'default_settings' => [
                        'pos.variable_weight_barcode' => [
                            'enabled' => true, 'prefix' => '2', 'total_length' => 13,
                            'product_code_start' => 1, 'product_code_length' => 5,
                            'value_start' => 6, 'value_length' => 5, 'value_decimal_places' => 3, 'embeds' => 'weight',
                        ],
                        'inventory.batch_pick_order' => 'fefo',
                    ],
                    'units' => [
                        ['code' => 'pcs', 'name' => 'Pieces'], ['code' => 'kg', 'name' => 'Kilogram'], ['code' => 'gram', 'name' => 'Gram'],
                    ],
                    'categories' => [
                        ['code' => 'VEG', 'name' => 'Vegetables'], ['code' => 'FRUIT', 'name' => 'Fruits'], ['code' => 'LEAFY', 'name' => 'Leafy Greens'],
                    ],
                    'lookups' => [
                        ['code' => 'wastage_reason', 'name' => 'Wastage Reason', 'values' => [
                            ['code' => 'spoiled', 'label' => 'Spoiled'],
                            ['code' => 'expired', 'label' => 'Expired'],
                            ['code' => 'damaged_transit', 'label' => 'Damaged in Transit'],
                        ]],
                    ],
                    'receipt_layouts' => ['default' => ['sections' => ['header', 'lines', 'totals', 'footer']]],
                    'ui_hints' => [],
                ],
            ],
        );
    }
}
