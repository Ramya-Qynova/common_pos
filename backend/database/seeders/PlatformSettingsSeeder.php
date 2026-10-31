<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

/**
 * The full setting catalogue from docs/MASTER_PROMPT.md section 6, seeded
 * as the platform-default layer of SettingsService's resolution chain.
 * Defaults follow the India preset (section 1.2) - INR, GST exclusive,
 * fiscal-year numbering - but every value here is just data: change it
 * (or override per-plan/tenant/branch) with no code change.
 */
class PlatformSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['auth.login_identifiers', ['shop_code_username'], 'json', null, 'Which identifiers a user may log in with.'],
            ['auth.access_token_minutes', 30, 'integer', null, 'Access token lifetime in minutes.'],
            ['auth.offline_grace_hours', 72, 'integer', null, 'How long an offline device may keep billing after its token expires.'],
            ['auth.max_failed_logins', 5, 'integer', null, 'Failed attempts before temporary account lockout.'],

            ['locale.currency_code', 'INR', 'string', null, 'ISO currency code.'],
            ['locale.currency_decimals', 2, 'integer', null, 'Display decimal places for money.'],
            ['locale.rounding_mode', 'half_up', 'enum', ['half_up', 'half_even'], 'Rounding mode for totals.'],
            ['locale.cash_rounding_step', '1.00', 'string', null, 'Smallest cash denomination to round to.'],
            ['locale.timezone', 'Asia/Kolkata', 'string', null, 'Default tenant timezone.'],
            ['locale.business_day_cutoff', '00:00', 'string', null, 'Business-day boundary (HH:MM, branch-local).'],
            ['locale.languages', ['en', 'ta', 'hi'], 'json', null, 'Enabled UI languages.'],

            ['tax.price_mode', 'exclusive', 'enum', ['inclusive', 'exclusive'], 'Default tax mode for new tax groups.'],
            ['tax.require_hsn', true, 'boolean', null, 'Require an HSN code on taxable products.'],

            ['inventory.allow_negative_stock', false, 'boolean', null, 'Block sales that would take stock negative (section 1.2).'],
            ['inventory.costing_method', 'weighted_average', 'enum', ['weighted_average', 'fifo'], 'Stock costing method.'],
            ['inventory.batch_pick_order', 'fefo', 'enum', ['fefo', 'fifo'], 'Batch picking order.'],
            ['inventory.expiry_block_days', 0, 'integer', null, 'Block sale of a batch within N days of expiry (0 = disabled).'],

            ['sales.shift_required', true, 'boolean', null, 'Require an open shift to create a sale.'],
            ['sales.discount_requires_reason', true, 'boolean', null, null],
            ['sales.allow_credit_sales', true, 'boolean', null, null],
            ['sales.hold_expiry_hours', 24, 'integer', null, null],
            ['sales.return_window_days', 7, 'integer', null, null],
            ['sales.cancel_window', 30, 'integer', null, 'Minutes after sale completion a cancel is still allowed.'],

            ['returns.require_inspection', true, 'boolean', null, null],

            ['shift.cash_difference_tolerance', '5.00', 'string', null, null],
            ['shift.close_requires_empty_sync_queue', true, 'boolean', null, null],

            ['numbering.invoice_format', '{prefix}{fy}-{number}', 'string', null, null],
            ['numbering.reset_period', 'fiscal_year', 'enum', ['never', 'fiscal_year', 'calendar_year'], null],
            ['numbering.offline_mode', 'device_series', 'enum', ['device_series', 'provisional_then_final'], null],

            ['offline.enabled', true, 'boolean', null, null],
            ['offline.allowed_operations', ['sale.create', 'sale.hold', 'sale.resume', 'customer.quick_add', 'cash.in', 'cash.out', 'shift.open'], 'json', null, null],
            ['offline.max_pending_operations', 500, 'integer', null, null],
            ['offline.snapshot_refresh_minutes', 30, 'integer', null, null],
            ['offline.snapshot_max_age_hours', 24, 'integer', null, null],
            ['offline.price_mismatch_policy', 'accept_device_price', 'enum', ['accept_device_price', 'accept_server_price'], null],

            ['notifications.receipt_channels', ['print'], 'json', null, null],

            ['security.session_idle_minutes', 30, 'integer', null, null],
            ['security.pin_for_approval', true, 'boolean', null, null],

            ['reports.max_range_days', 366, 'integer', null, null],
        ];

        foreach ($rows as [$key, $value, $type, $allowed, $description]) {
            PlatformSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'type' => $type, 'allowed_values' => $allowed, 'description' => $description],
            );
        }
    }
}
