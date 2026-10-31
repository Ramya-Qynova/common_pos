<?php

use App\Exceptions\ApiException;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;
use App\Services\Purchasing\SupplierLedgerService;
use Illuminate\Support\Str;

test('the supplier balance (what we owe) always equals the sum of their ledger entries', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-SL-1', tenantName: 'Supplier Ledger Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $supplier = Supplier::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'SUP-1', 'name' => 'Test Supplier']);

    $service = app(SupplierLedgerService::class);
    $service->recordEntry($tenant->id, $supplier->id, 'debit', '1000.0000', 'opening_balance', null, null, $owner->id);
    $service->makePayment($tenant->id, $supplier->id, '400.0000', null, null, $owner->id);

    $balance = $service->currentBalance($tenant->id, $supplier->id);
    expect(bccomp($balance, '600.0000', 4))->toBe(0);

    $ledgerSum = SupplierLedger::withoutTenantScope()->where('supplier_id', $supplier->id)->get()
        ->reduce(fn ($carry, $e) => bcadd($carry, $e->direction === 'debit' ? $e->amount : bcmul($e->amount, '-1', 4), 4), '0.0000');
    expect(bccomp($ledgerSum, $balance, 4))->toBe(0);
});

test('allocating a supplier payment beyond what is outstanding is rejected', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-SL-2', tenantName: 'Supplier Ledger Test Shop 2', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $supplier = Supplier::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'SUP-2', 'name' => 'Test Supplier 2']);

    $service = app(SupplierLedgerService::class);
    $debit = $service->recordEntry($tenant->id, $supplier->id, 'debit', '50.0000', 'opening_balance', null, null, $owner->id);
    $payment = $service->makePayment($tenant->id, $supplier->id, '100.0000', null, null, $owner->id);

    expect(fn () => $service->allocate($payment->id, $debit->id, '50.0001'))->toThrow(ApiException::class);
    $service->allocate($payment->id, $debit->id, '50.0000'); // exactly the outstanding amount is fine
    $debit->refresh();
    expect(bccomp($debit->allocated_amount, '50.0000', 4))->toBe(0);
});
