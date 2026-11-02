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

        // section 2 Release 2 Item 3: Textile / Apparel / Footwear
        $textile = Template::updateOrCreate(
            ['code' => 'textile'],
            ['name' => 'Textile & Apparel', 'description' => 'Apparel, footwear, fabrics, size/color variant matrix, seasonal lines.', 'is_active' => true],
        );

        TemplateVersion::updateOrCreate(
            ['template_id' => $textile->id, 'version' => 1],
            [
                'is_published' => true,
                'published_at' => now(),
                'data' => [
                    'modules' => ['core', 'catalog', 'inventory', 'pos', 'sales_mgmt', 'customers', 'finance', 'reports', 'variants'],
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
                    'default_settings' => [
                        'inventory.costing_method' => 'fifo',
                    ],
                    'units' => [
                        ['code' => 'pcs', 'name' => 'Pieces'],
                        ['code' => 'mtr', 'name' => 'Meters'],
                        ['code' => 'yd', 'name' => 'Yards'],
                        ['code' => 'roll', 'name' => 'Rolls'],
                        ['code' => 'dzn', 'name' => 'Dozens'],
                        ['code' => 'pair', 'name' => 'Pairs'],
                    ],
                    'categories' => [
                        ['code' => 'MEN', 'name' => "Men's Apparel"],
                        ['code' => 'WOMEN', 'name' => "Women's Apparel"],
                        ['code' => 'KIDS', 'name' => 'Kids & Infants'],
                        ['code' => 'FABRIC', 'name' => 'Fabrics & Yardage'],
                        ['code' => 'ACCESSORIES', 'name' => 'Accessories & Footwear'],
                    ],
                    'lookups' => [
                        ['code' => 'season', 'name' => 'Season', 'values' => [
                            ['code' => 'summer', 'label' => 'Summer'],
                            ['code' => 'winter', 'label' => 'Winter'],
                            ['code' => 'monsoon', 'label' => 'Monsoon'],
                            ['code' => 'festive', 'label' => 'Festive / Wedding'],
                        ]],
                        ['code' => 'fit_type', 'name' => 'Fit Type', 'values' => [
                            ['code' => 'regular', 'label' => 'Regular Fit'],
                            ['code' => 'slim', 'label' => 'Slim Fit'],
                            ['code' => 'relaxed', 'label' => 'Relaxed Fit'],
                            ['code' => 'oversized', 'label' => 'Oversized'],
                        ]],
                    ],
                    'custom_fields' => [
                        ['entity_type' => 'product', 'code' => 'fabric', 'label' => 'Fabric / Material', 'type' => 'text', 'required' => false, 'searchable' => true, 'show_in_list' => true, 'show_on_receipt' => false],
                        ['entity_type' => 'product', 'code' => 'style_code', 'label' => 'Style / Design Code', 'type' => 'text', 'required' => false, 'searchable' => true, 'show_in_list' => true, 'show_on_receipt' => true],
                        ['entity_type' => 'product', 'code' => 'gender', 'label' => 'Target Gender', 'type' => 'select', 'required' => false, 'searchable' => true, 'show_in_list' => false, 'show_on_receipt' => false],
                    ],
                    'receipt_layouts' => ['default' => ['sections' => ['header', 'lines', 'totals', 'footer']]],
                    'ui_hints' => ['catalog.variant_matrix' => true],
                ],
            ],
        );

        // section 2 Release 2 Item 3: Medical / Pharmacy / Healthcare
        $medical = Template::updateOrCreate(
            ['code' => 'medical'],
            ['name' => 'Pharmacy & Healthcare', 'description' => 'Pharmaceuticals, drug schedule tracking, batch/expiry controls, FEFO picking.', 'is_active' => true],
        );

        TemplateVersion::updateOrCreate(
            ['template_id' => $medical->id, 'version' => 1],
            [
                'is_published' => true,
                'published_at' => now(),
                'data' => [
                    'modules' => ['core', 'catalog', 'inventory', 'pos', 'sales_mgmt', 'purchases', 'customers', 'finance', 'reports', 'batch_expiry', 'serial_tracking'],
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
                    'default_settings' => [
                        'inventory.batch_pick_order' => 'fefo',
                        'inventory.expiry_block_days' => 30,
                        'inventory.costing_method' => 'fifo',
                    ],
                    'units' => [
                        ['code' => 'strip', 'name' => 'Strip'],
                        ['code' => 'tab', 'name' => 'Tablet'],
                        ['code' => 'btl', 'name' => 'Bottle'],
                        ['code' => 'box', 'name' => 'Box'],
                        ['code' => 'tube', 'name' => 'Tube'],
                        ['code' => 'vial', 'name' => 'Vial'],
                        ['code' => 'amp', 'name' => 'Ampoule'],
                        ['code' => 'sachet', 'name' => 'Sachet'],
                    ],
                    'categories' => [
                        ['code' => 'RX', 'name' => 'Prescription Drugs'],
                        ['code' => 'OTC', 'name' => 'Over-the-Counter (OTC)'],
                        ['code' => 'FIRST_AID', 'name' => 'First Aid & Surgical'],
                        ['code' => 'DEVICES', 'name' => 'Medical Devices & Equipment'],
                        ['code' => 'SUPPLEMENTS', 'name' => 'Vitamins & Supplements'],
                    ],
                    'lookups' => [
                        ['code' => 'drug_schedule', 'name' => 'Drug Schedule', 'values' => [
                            ['code' => 'general', 'label' => 'General / Non-Scheduled'],
                            ['code' => 'schedule_h', 'label' => 'Schedule H (Prescription Only)'],
                            ['code' => 'schedule_h1', 'label' => 'Schedule H1 (High Risk / Recorded)'],
                            ['code' => 'schedule_x', 'label' => 'Schedule X (Narcotics)'],
                        ]],
                        ['code' => 'storage_condition', 'name' => 'Storage Condition', 'values' => [
                            ['code' => 'ambient', 'label' => 'Ambient (15-25°C)'],
                            ['code' => 'refrigerated', 'label' => 'Refrigerated (2-8°C)'],
                            ['code' => 'cold', 'label' => 'Cold Storage (<2°C)'],
                        ]],
                    ],
                    'custom_fields' => [
                        ['entity_type' => 'product', 'code' => 'generic_name', 'label' => 'Generic / Composition Name', 'type' => 'text', 'required' => false, 'searchable' => true, 'show_in_list' => true, 'show_on_receipt' => false],
                        ['entity_type' => 'product', 'code' => 'drug_license_no', 'label' => 'Drug License Number', 'type' => 'text', 'required' => false, 'searchable' => false, 'show_in_list' => false, 'show_on_receipt' => false],
                        ['entity_type' => 'product', 'code' => 'hsn_code', 'label' => 'HSN / Tax Classification', 'type' => 'text', 'required' => false, 'searchable' => true, 'show_in_list' => true, 'show_on_receipt' => true],
                    ],
                    'receipt_layouts' => ['default' => ['sections' => ['header', 'lines', 'totals', 'footer']]],
                    'ui_hints' => ['inventory.highlight_expiring' => true],
                ],
            ],
        );

        // section 2 Release 2 Item 3: Wholesale / B2B Distribution
        $wholesale = Template::updateOrCreate(
            ['code' => 'wholesale'],
            ['name' => 'Wholesale & B2B Distribution', 'description' => 'B2B sales, tier pricing, case pack / pallet units, credit terms, multi-tier orders.', 'is_active' => true],
        );

        TemplateVersion::updateOrCreate(
            ['template_id' => $wholesale->id, 'version' => 1],
            [
                'is_published' => true,
                'published_at' => now(),
                'data' => [
                    'modules' => ['core', 'catalog', 'inventory', 'pos', 'sales_mgmt', 'purchases', 'customers', 'finance', 'reports', 'price_lists_advanced'],
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
                    'default_settings' => [
                        'sales.allow_credit_sales' => true,
                        'inventory.costing_method' => 'fifo',
                    ],
                    'units' => [
                        ['code' => 'pcs', 'name' => 'Pieces'],
                        ['code' => 'box', 'name' => 'Box'],
                        ['code' => 'ctn', 'name' => 'Carton'],
                        ['code' => 'plt', 'name' => 'Pallet'],
                        ['code' => 'pack', 'name' => 'Pack'],
                        ['code' => 'kg', 'name' => 'Kilogram'],
                        ['code' => 'bag', 'name' => 'Bag'],
                    ],
                    'categories' => [
                        ['code' => 'BULK_FOOD', 'name' => 'Bulk Staples & Grains'],
                        ['code' => 'FMCG_WHOLESALE', 'name' => 'FMCG Case Packs'],
                        ['code' => 'COMMERCIAL', 'name' => 'Commercial & Institutional'],
                        ['code' => 'RAW_MATERIALS', 'name' => 'Industrial Raw Materials'],
                    ],
                    'lookups' => [
                        ['code' => 'payment_terms', 'name' => 'Payment Terms', 'values' => [
                            ['code' => 'immediate', 'label' => 'Immediate / Advance'],
                            ['code' => 'net_15', 'label' => 'Net 15 Days'],
                            ['code' => 'net_30', 'label' => 'Net 30 Days'],
                            ['code' => 'net_60', 'label' => 'Net 60 Days'],
                        ]],
                        ['code' => 'transport_mode', 'name' => 'Transport Mode', 'values' => [
                            ['code' => 'road_truck', 'label' => 'Road Transport / Truck'],
                            ['code' => 'rail_cargo', 'label' => 'Rail Freight'],
                            ['code' => 'air_cargo', 'label' => 'Air Cargo'],
                            ['code' => 'express_courier', 'label' => 'Express Courier'],
                        ]],
                    ],
                    'custom_fields' => [
                        ['entity_type' => 'product', 'code' => 'moq', 'label' => 'Minimum Order Quantity (MOQ)', 'type' => 'number', 'required' => false, 'searchable' => false, 'show_in_list' => true, 'show_on_receipt' => false],
                        ['entity_type' => 'product', 'code' => 'case_pack_size', 'label' => 'Case Pack Size', 'type' => 'number', 'required' => false, 'searchable' => false, 'show_in_list' => true, 'show_on_receipt' => false],
                        ['entity_type' => 'customer', 'code' => 'tax_id_gstin', 'label' => 'Customer Tax ID / GSTIN', 'type' => 'text', 'required' => false, 'searchable' => true, 'show_in_list' => true, 'show_on_receipt' => true],
                    ],
                    'receipt_layouts' => ['default' => ['sections' => ['header', 'lines', 'totals', 'footer']]],
                    'ui_hints' => ['sales.show_case_pack' => true],
                ],
            ],
        );
    }
}
