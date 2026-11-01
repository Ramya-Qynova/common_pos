<?php

use App\Models\Customer;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

test('GET /customers searches by name/phone/code', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-CUST-1', tenantName: 'Customer Search Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    Customer::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'CUST-1', 'name' => 'Jane Doe', 'phone' => '9998887777', 'is_active' => true]);
    Customer::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'CUST-2', 'name' => 'John Smith', 'phone' => '1112223333', 'is_active' => true]);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])->getJson('/api/v1/customers?search=9998887777');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Jane Doe');
});
