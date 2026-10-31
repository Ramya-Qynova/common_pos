<?php

use App\Models\Category;
use App\Models\LookupType;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\TenantModule;
use App\Models\TenantTemplateSnapshot;
use App\Models\Unit;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\TemplateUpdateService;
use App\Services\Settings\SettingsService;

/**
 * section 12 Phase 6 exit gate: "Template data drives the full grocery
 * flow with NO grocery-specific code branches." Every assertion below is
 * produced by the exact same OnboardingService/TemplateUpdateService code
 * that general_retail uses - only the DATA differs (see
 * backend/database/seeders/TemplatesSeeder.php's `grocery` entry). Nothing
 * in application code ever compares a template code or name (verified
 * separately by `node scripts/check-dynamic-only.js`).
 */
test('onboarding a grocery tenant enables the weighing module; onboarding a general_retail tenant does not', function () {
    $grocery = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-GROC-1', tenantName: 'Grocery Shop', planCode: 'standard',
        templateCode: 'grocery', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $retail = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-GROC-2', tenantName: 'Retail Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );

    expect(TenantModule::withoutTenantScope()->where('tenant_id', $grocery->id)->pluck('module_code'))->toContain('weighing', 'batch_expiry');
    expect(TenantModule::withoutTenantScope()->where('tenant_id', $retail->id)->pluck('module_code'))->not->toContain('weighing');
});

test('applying a freshly onboarded grocery tenant\'s template creates its units, categories, wastage-reason lookup, and settings override - all from data', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-GROC-3', tenantName: 'Grocery Shop 3', planCode: 'standard',
        templateCode: 'grocery', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    // Nothing exists yet - onboarding only applies modules + roles (docs/DECISIONS.md).
    expect(Unit::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);

    $diff = app(TemplateUpdateService::class)->apply($tenant->id, $owner->id);

    expect(collect($diff['added_units'])->pluck('code')->all())->toEqual(['pcs', 'kg', 'gram']);
    expect(collect($diff['added_categories'])->pluck('code')->all())->toEqual(['VEG', 'FRUIT', 'LEAFY']);
    expect(collect($diff['added_lookups'])->pluck('code')->all())->toEqual(['wastage_reason']);

    expect(Unit::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('code'))->toContain('kg', 'gram', 'pcs');
    expect(Category::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('code'))->toContain('VEG', 'FRUIT', 'LEAFY');

    $lookupType = LookupType::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'wastage_reason')->first();
    expect($lookupType)->not->toBeNull();
    expect($lookupType->values->pluck('code'))->toContain('spoiled', 'expired', 'damaged_transit');

    // The variable-weight barcode setting is a TENANT-LEVEL override the template provided as data.
    $format = app(SettingsService::class)->get('pos.variable_weight_barcode', $tenant->id);
    expect($format['enabled'])->toBeTrue();

    $snapshot = TenantTemplateSnapshot::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    expect($snapshot->template_version)->toBe(1);
});

test('applying a general_retail tenant\'s template adds its OWN units only - the same code, different data, no grocery leakage', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-GROC-4', tenantName: 'Retail Shop 4', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $diff = app(TemplateUpdateService::class)->apply($tenant->id, $owner->id);

    expect(collect($diff['added_units'])->pluck('code')->all())->toEqual(['pcs', 'kg']);
    expect($diff['added_categories'])->toBe([]);
    expect($diff['added_lookups'])->toBe([]);

    // No grocery-only setting override exists here - the platform default (disabled) still applies.
    $format = app(SettingsService::class)->get('pos.variable_weight_barcode', $tenant->id);
    expect($format['enabled'])->toBeFalse();
});

test('apply() is idempotent - calling it twice does not duplicate rows and the second call has nothing left to add', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-GROC-5', tenantName: 'Grocery Shop 5', planCode: 'standard',
        templateCode: 'grocery', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    app(TemplateUpdateService::class)->apply($tenant->id, $owner->id);
    $secondDiff = app(TemplateUpdateService::class)->apply($tenant->id, $owner->id);

    expect($secondDiff['added_units'])->toBe([]);
    expect($secondDiff['added_categories'])->toBe([]);
    expect($secondDiff['added_lookups'])->toBe([]);
    expect(Unit::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'kg')->count())->toBe(1);
});

test('publishing a newer template version surfaces only what is genuinely new in a preview, and apply() adds just that - never touching what is already there', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-GROC-6', tenantName: 'Grocery Shop 6', planCode: 'standard',
        templateCode: 'grocery', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    app(TemplateUpdateService::class)->apply($tenant->id, $owner->id); // tenant fully on v1

    $groceryTemplate = Template::where('code', 'grocery')->first();
    $v1 = $groceryTemplate->latestPublished();
    TemplateVersion::create([
        'template_id' => $groceryTemplate->id, 'version' => 2, 'is_published' => true, 'published_at' => now(),
        'data' => array_merge($v1->data, [
            'categories' => array_merge($v1->data['categories'], [['code' => 'DAIRY', 'name' => 'Dairy']]),
        ]),
    ]);

    $preview = app(TemplateUpdateService::class)->preview($tenant->id);
    expect($preview['target_version'])->toBe(2);
    expect(collect($preview['added_categories'])->pluck('code')->all())->toEqual(['DAIRY']);
    expect($preview['added_units'])->toBe([]); // already applied - v2 didn't change units

    app(TemplateUpdateService::class)->apply($tenant->id, $owner->id);

    expect(Category::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('code'))->toContain('VEG', 'FRUIT', 'LEAFY', 'DAIRY');
    expect(TenantTemplateSnapshot::withoutTenantScope()->where('tenant_id', $tenant->id)->first()->template_version)->toBe(2);
});
