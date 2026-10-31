<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * G11: the complete catalogue of permission codes. Roles are just named
 * sets of these codes (section 6 "default roles/permission maps" in a
 * template) - nothing in application code ever compares a role name.
 */
class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['core.settings.manage', 'core', 'Change tenant/branch settings'],
            ['core.users.manage', 'core', 'Create/edit/deactivate users'],
            ['core.roles.manage', 'core', 'Create/edit roles and permission maps'],
            ['core.branches.manage', 'core', 'Create/edit branches, warehouses, registers'],
            ['core.audit.view', 'core', 'View audit logs and security events'],

            ['catalog.products.manage', 'catalog', 'Create/edit products, categories, prices'],

            ['inventory.adjust', 'inventory', 'Create stock adjustments / opening stock'],
            ['inventory.transfer', 'inventory', 'Create stock transfers'],
            ['inventory.view', 'inventory', 'View stock balances and ledger'],

            ['pos.sales.create', 'pos', 'Create a sale'],
            ['pos.sales.discount', 'pos', 'Apply a discount beyond the default limit'],
            ['pos.sales.cancel', 'pos', 'Cancel a completed sale'],
            ['pos.sales.return', 'pos', 'Process a return/refund'],
            ['pos.shift.open', 'pos', 'Open a cashier shift'],
            ['pos.shift.close', 'pos', 'Close a cashier shift'],

            ['purchases.manage', 'purchases', 'Create purchase orders and receive stock'],

            ['customers.manage', 'customers', 'Create/edit customers and collect payments'],

            ['finance.expenses.manage', 'finance', 'Record expenses and cash movements'],
            ['finance.daily_close', 'finance', 'Perform the daily finance close'],
            ['finance.reopen', 'finance', 'Reopen a closed business day'],

            ['reports.view', 'reports', 'View and export reports'],

            ['approvals.approve', 'core', 'Approve or reject a pending approval request'],
        ];

        foreach ($permissions as [$code, $moduleCode, $description]) {
            Permission::updateOrCreate(['code' => $code], ['module_code' => $moduleCode, 'description' => $description]);
        }
    }
}
