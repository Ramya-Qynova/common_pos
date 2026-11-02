<?php

use App\Models\Category;
use App\Models\CustomFieldDefinition;
use App\Models\LookupType;
use App\Models\TenantModule;
use App\Models\Unit;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\TemplateUpdateService;
use App\Services\Settings\SettingsService;

test('onboarding tenant with textile template applies textile modules, units, categories, lookups, and custom fields', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-TEX-'.uniqid(),
        tenantName: 'Textile Boutique',
        planCode: 'standard',
        templateCode: 'textile',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    // Verify module activation
    $modules = TenantModule::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('module_code');
    expect($modules)->toContain('variants', 'catalog', 'inventory', 'pos');

    // Apply template data
    $diff = app(TemplateUpdateService::class)->apply($tenant->id, $owner->id);

    // Units
    $unitCodes = Unit::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('code');
    expect($unitCodes)->toContain('mtr', 'yd', 'roll', 'dzn', 'pair', 'pcs');

    // Categories
    $categoryCodes = Category::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('code');
    expect($categoryCodes)->toContain('MEN', 'WOMEN', 'KIDS', 'FABRIC', 'ACCESSORIES');

    // Lookups
    $seasonLookup = LookupType::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'season')->first();
    expect($seasonLookup)->not->toBeNull();
    expect($seasonLookup->values->pluck('code'))->toContain('summer', 'winter', 'monsoon', 'festive');

    $fitLookup = LookupType::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'fit_type')->first();
    expect($fitLookup)->not->toBeNull();
    expect($fitLookup->values->pluck('code'))->toContain('regular', 'slim', 'relaxed', 'oversized');

    // Custom fields
    $customFields = CustomFieldDefinition::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('code');
    expect($customFields)->toContain('fabric', 'style_code', 'gender');

    // Costing method setting
    $costingMethod = app(SettingsService::class)->get('inventory.costing_method', $tenant->id);
    expect($costingMethod)->toBe('fifo');
});

test('onboarding tenant with medical template applies batch_expiry, FEFO order, and pharmaceutical custom fields', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-MED-'.uniqid(),
        tenantName: 'City Pharmacy',
        planCode: 'standard',
        templateCode: 'medical',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    // Modules
    $modules = TenantModule::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('module_code');
    expect($modules)->toContain('batch_expiry', 'serial_tracking', 'purchases');

    // Apply
    app(TemplateUpdateService::class)->apply($tenant->id, $owner->id);

    // Units
    $unitCodes = Unit::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('code');
    expect($unitCodes)->toContain('strip', 'tab', 'btl', 'box', 'tube', 'vial', 'amp', 'sachet');

    // Categories
    $categoryCodes = Category::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('code');
    expect($categoryCodes)->toContain('RX', 'OTC', 'FIRST_AID', 'DEVICES', 'SUPPLEMENTS');

    // Lookups
    $drugSchedule = LookupType::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'drug_schedule')->first();
    expect($drugSchedule)->not->toBeNull();
    expect($drugSchedule->values->pluck('code'))->toContain('general', 'schedule_h', 'schedule_h1', 'schedule_x');

    $storageCondition = LookupType::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'storage_condition')->first();
    expect($storageCondition)->not->toBeNull();
    expect($storageCondition->values->pluck('code'))->toContain('ambient', 'refrigerated', 'cold');

    // Custom fields
    $customFields = CustomFieldDefinition::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('code');
    expect($customFields)->toContain('generic_name', 'drug_license_no', 'hsn_code');

    // Settings
    expect(app(SettingsService::class)->get('inventory.batch_pick_order', $tenant->id))->toBe('fefo');
    expect(app(SettingsService::class)->get('inventory.expiry_block_days', $tenant->id))->toBe(30);
    expect(app(SettingsService::class)->get('inventory.costing_method', $tenant->id))->toBe('fifo');
});

test('onboarding tenant with wholesale template applies tier units, credit settings, and B2B lookups', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-WHS-'.uniqid(),
        tenantName: 'National Distributors',
        planCode: 'standard',
        templateCode: 'wholesale',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    // Modules
    $modules = TenantModule::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('module_code');
    expect($modules)->toContain('price_lists_advanced', 'purchases');

    // Apply
    app(TemplateUpdateService::class)->apply($tenant->id, $owner->id);

    // Units
    $unitCodes = Unit::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('code');
    expect($unitCodes)->toContain('ctn', 'plt', 'box', 'bag', 'kg', 'pack', 'pcs');

    // Categories
    $categoryCodes = Category::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('code');
    expect($categoryCodes)->toContain('BULK_FOOD', 'FMCG_WHOLESALE', 'COMMERCIAL', 'RAW_MATERIALS');

    // Lookups
    $paymentTerms = LookupType::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'payment_terms')->first();
    expect($paymentTerms)->not->toBeNull();
    expect($paymentTerms->values->pluck('code'))->toContain('immediate', 'net_15', 'net_30', 'net_60');

    $transport = LookupType::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'transport_mode')->first();
    expect($transport)->not->toBeNull();
    expect($transport->values->pluck('code'))->toContain('road_truck', 'rail_cargo', 'air_cargo', 'express_courier');

    // Custom fields
    $customFields = CustomFieldDefinition::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('code');
    expect($customFields)->toContain('moq', 'case_pack_size', 'tax_id_gstin');

    // Settings
    expect(app(SettingsService::class)->get('sales.allow_credit_sales', $tenant->id))->toBeTrue();
    expect(app(SettingsService::class)->get('inventory.costing_method', $tenant->id))->toBe('fifo');
});

test('GET /templates and GET /custom-fields endpoints return template and custom field catalogues', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-TMPL-'.uniqid(),
        tenantName: 'Template Test Shop',
        planCode: 'standard',
        templateCode: 'textile',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    app(TemplateUpdateService::class)->apply($tenant->id, $owner->id);

    $login = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code,
        'username' => 'owner',
        'password' => 'correct-password',
    ])->json('data');

    $authHeader = ['Authorization' => 'Bearer '.$login['access_token']];

    // List all templates
    $res = test()->getJson('/api/v1/templates', $authHeader);
    $res->assertStatus(200)->assertJsonPath('success', true);
    $templateCodes = collect($res->json('data'))->pluck('code')->all();
    expect($templateCodes)->toContain('general_retail', 'grocery', 'textile', 'medical', 'wholesale');

    // Show single template
    $resSingle = test()->getJson('/api/v1/templates/textile', $authHeader);
    $resSingle->assertStatus(200)->assertJsonPath('data.code', 'textile');

    // List custom fields
    $resCf = test()->getJson('/api/v1/custom-fields', $authHeader);
    $resCf->assertStatus(200)->assertJsonPath('success', true);
    $cfCodes = collect($resCf->json('data'))->pluck('code')->all();
    expect($cfCodes)->toContain('fabric', 'style_code', 'gender');

    // Create a new custom field via API
    $createRes = test()->postJson('/api/v1/custom-fields', [
        'entity_type' => 'product',
        'code' => 'brand_line',
        'label' => 'Brand Line',
        'type' => 'text',
        'searchable' => true,
    ], $authHeader);

    $createRes->assertStatus(201)->assertJsonPath('data.code', 'brand_line');
});
