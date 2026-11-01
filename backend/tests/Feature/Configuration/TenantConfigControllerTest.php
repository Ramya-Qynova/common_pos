<?php

use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

/**
 * section 10 "Configuration": GET /tenant/config is the client's one call
 * for "what can I bill with" - this phase extends it with warehouses,
 * registers, price lists, payment methods, and units, none of which were
 * exposed before (a POS screen cannot pick any of these out of thin air).
 *
 * Onboarding itself only creates the tenant/org/role skeleton (warehouses,
 * registers) - price lists and payment methods are deliberately a setup-
 * wizard concern (section 8 "go-live requires ... >=1 payment method"), so
 * this fixture creates them explicitly, the same way every other test
 * fixture in this codebase does (and the same way `php artisan demo:seed`
 * does for a real tenant).
 */
test('GET /tenant/config includes warehouses, registers, price lists, payment methods, and units for the onboarded tenant', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-CFG-1', tenantName: 'Config Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    PaymentMethod::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'confirmation_mode' => 'instant', 'allows_overpayment' => true,
    ]);
    PriceList::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true,
    ]);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])->getJson('/api/v1/tenant/config');

    $response->assertOk();
    expect($response->json('data.warehouses'))->not->toBeEmpty();
    expect($response->json('data.registers'))->not->toBeEmpty();
    expect($response->json('data.price_lists'))->not->toBeEmpty();
    expect(collect($response->json('data.payment_methods'))->pluck('code'))->toContain('cash');
    expect($response->json('data.units'))->toBeArray();
});
