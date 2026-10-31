<?php

use App\Models\Branch;
use App\Models\PlanSetting;
use App\Models\Tenant;
use App\Services\Onboarding\OnboardingService;
use App\Services\Settings\SettingsService;

function onboardForSettings(): Tenant
{
    return app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-SET',
        tenantName: 'Settings Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
}

test('a setting with no tenant or plan override resolves to the platform default', function () {
    $tenant = onboardForSettings();

    expect(app(SettingsService::class)->get('sales.shift_required', $tenant->id))->toBeTrue();
});

test('a plan-level override wins over the platform default when the tenant has no override of its own', function () {
    $tenant = onboardForSettings();

    PlanSetting::create(['plan_id' => $tenant->plan_id, 'key' => 'sales.shift_required', 'value' => false]);

    expect(app(SettingsService::class)->get('sales.shift_required', $tenant->id))->toBeFalse();
});

test('changing a tenant setting changes resolved behaviour with no code change, and the change is visible on the very next read (cache invalidation)', function () {
    $service = app(SettingsService::class);
    $tenant = onboardForSettings();

    expect($service->get('inventory.allow_negative_stock', $tenant->id))->toBeFalse();

    // Read once first, so a stale cache entry would exist if invalidation were broken.
    $service->get('inventory.allow_negative_stock', $tenant->id);

    $service->set($tenant->id, 'inventory.allow_negative_stock', true);

    expect($service->get('inventory.allow_negative_stock', $tenant->id))->toBeTrue();
});

test('a branch-level override wins over the tenant-level value, but only when that branch is asked for', function () {
    $service = app(SettingsService::class);
    $tenant = onboardForSettings();
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $service->set($tenant->id, 'sales.shift_required', false); // tenant-wide: false
    $service->set($tenant->id, 'sales.shift_required', true, $branch->id); // this branch: true

    expect($service->get('sales.shift_required', $tenant->id, $branch->id))->toBeTrue();
    expect($service->get('sales.shift_required', $tenant->id, null))->toBeFalse();
});

test('an unknown setting key throws rather than silently returning null', function () {
    $tenant = onboardForSettings();

    app(SettingsService::class)->get('no.such.key', $tenant->id);
})->throws(RuntimeException::class);
