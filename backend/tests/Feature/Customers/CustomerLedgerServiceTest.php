<?php

use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Customers\CustomerLedgerService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

function onboardForLedger(string $shopCode): Tenant
{
    return app(OnboardingService::class)->createTenant(
        shopCode: $shopCode,
        tenantName: 'Ledger Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
}

function makeCustomer(int $tenantId, string $code = 'CUST-1'): Customer
{
    return Customer::withoutTenantScope()->create([
        'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'code' => $code, 'name' => 'Test Customer', 'is_active' => true,
    ]);
}

test('the customer balance always equals the sum of their ledger entries', function () {
    $tenant = onboardForLedger('SHOP-CL-1');
    $customer = makeCustomer($tenant->id);
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $service = app(CustomerLedgerService::class);

    $service->recordEntry($tenant->id, $customer->id, 'debit', '500.0000', 'opening_balance', null, 'opening balance', $owner->id);
    $service->recordEntry($tenant->id, $customer->id, 'debit', '200.0000', 'manual_adjustment', null, 'extra charge', $owner->id);
    $service->collectPayment($tenant->id, $customer->id, '300.0000', null, null, $owner->id);

    $balance = $service->currentBalance($tenant->id, $customer->id);
    expect(bccomp($balance, '400.0000', 4))->toBe(0); // 500 + 200 - 300

    $ledgerSum = CustomerLedger::withoutTenantScope()->where('customer_id', $customer->id)->get()
        ->reduce(fn ($carry, $e) => bcadd($carry, $e->direction === 'debit' ? $e->amount : bcmul($e->amount, '-1', 4), 4), '0.0000');

    expect(bccomp($ledgerSum, $balance, 4))->toBe(0);
});

test('a payment can be allocated against a specific outstanding charge, and over-allocation is rejected', function () {
    $tenant = onboardForLedger('SHOP-CL-2');
    $customer = makeCustomer($tenant->id);
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $service = app(CustomerLedgerService::class);

    $debit = $service->recordEntry($tenant->id, $customer->id, 'debit', '100.0000', 'opening_balance', null, null, $owner->id);
    $payment = $service->collectPayment($tenant->id, $customer->id, '60.0000', null, null, $owner->id);

    $service->allocate($payment->id, $debit->id, '60.0000');
    $debit->refresh();
    expect(bccomp($debit->allocated_amount, '60.0000', 4))->toBe(0);

    // The debit only has 40.00 outstanding now - allocating more than that must be rejected.
    expect(fn () => $service->allocate($payment->id, $debit->id, '50.0000'))->toThrow(ApiException::class);

    // A second payment allocating more than ITS OWN remaining amount must also be rejected.
    $debit2 = $service->recordEntry($tenant->id, $customer->id, 'debit', '1000.0000', 'manual_adjustment', null, null, $owner->id);
    expect(fn () => $service->allocate($payment->id, $debit2->id, '60.0000'))->toThrow(ApiException::class); // payment already fully allocated
});
