<?php

use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Services\Customers\LoyaltyService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

test('loyalty points earned and redeemed leave a balance equal to the sum of transactions, and over-redemption is rejected', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-LOY-1', tenantName: 'Loyalty Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $customer = Customer::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'CUST-LOY', 'name' => 'Loyal Customer']);
    $service = app(LoyaltyService::class);

    $service->earn($tenant->id, $customer->id, '100.0000');
    $service->redeem($tenant->id, $customer->id, '30.0000');

    $account = LoyaltyAccount::withoutTenantScope()->where('customer_id', $customer->id)->first();
    expect(bccomp($account->points_balance, '70.0000', 4))->toBe(0);

    expect(fn () => $service->redeem($tenant->id, $customer->id, '1000.0000'))->toThrow(ApiException::class);
});
