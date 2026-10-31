<?php

use App\Exceptions\ApiException;
use App\Models\CashierShift;
use App\Models\Register;
use App\Models\User;
use App\Services\Finance\ShiftService;
use App\Services\Onboarding\OnboardingService;

function onboardForShift(string $shopCode): array
{
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: $shopCode, tenantName: 'Shift Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    return [$tenant, $owner, $register];
}

test('closing a shift computes expected cash via the formula: opening + cash_sale - cash_refund - expense + cash_in - cash_out', function () {
    [$tenant, $owner, $register] = onboardForShift('SHOP-SHIFT-1');
    $service = app(ShiftService::class);

    $shift = $service->open($tenant->id, $register->id, '100.0000', $owner->id);
    $service->recordCashMovement($tenant->id, $shift->id, 'cash_sale', '500.0000', null, $owner->id);
    $service->recordCashMovement($tenant->id, $shift->id, 'cash_refund', '50.0000', null, $owner->id);
    $service->recordCashMovement($tenant->id, $shift->id, 'expense', '30.0000', 'tea for staff', $owner->id);
    $service->recordCashMovement($tenant->id, $shift->id, 'cash_in', '20.0000', 'owner top-up', $owner->id);
    $service->recordCashMovement($tenant->id, $shift->id, 'cash_out', '10.0000', 'bank deposit', $owner->id);

    // expected = 100 + 500 - 50 - 30 + 20 - 10 = 530
    $closed = $service->close($tenant->id, $shift->id, '530.0000', $owner->id);

    expect(bccomp($closed->expected_cash, '530.0000', 4))->toBe(0);
    expect(bccomp($closed->cash_difference, '0.0000', 4))->toBe(0);
});

test('a cash difference is recorded when the counted cash does not match expected', function () {
    [$tenant, $owner, $register] = onboardForShift('SHOP-SHIFT-2');
    $service = app(ShiftService::class);

    $shift = $service->open($tenant->id, $register->id, '100.0000', $owner->id);
    $service->recordCashMovement($tenant->id, $shift->id, 'cash_sale', '200.0000', null, $owner->id);

    $closed = $service->close($tenant->id, $shift->id, '290.0000', $owner->id); // expected 300, counted 290

    expect(bccomp($closed->expected_cash, '300.0000', 4))->toBe(0);
    expect(bccomp($closed->cash_difference, '-10.0000', 4))->toBe(0);
});

test('only one shift can be open per register at a time', function () {
    [$tenant, $owner, $register] = onboardForShift('SHOP-SHIFT-3');
    $service = app(ShiftService::class);

    $service->open($tenant->id, $register->id, '100.0000', $owner->id);

    expect(fn () => $service->open($tenant->id, $register->id, '50.0000', $owner->id))->toThrow(ApiException::class);
});

test('closed shifts are immutable: no further cash movement can be recorded, and the row itself cannot be re-saved', function () {
    [$tenant, $owner, $register] = onboardForShift('SHOP-SHIFT-4');
    $service = app(ShiftService::class);

    $shift = $service->open($tenant->id, $register->id, '100.0000', $owner->id);
    $service->close($tenant->id, $shift->id, '100.0000', $owner->id);

    expect(fn () => $service->recordCashMovement($tenant->id, $shift->id, 'cash_in', '5.0000', null, $owner->id))
        ->toThrow(ApiException::class);

    $closedShift = CashierShift::withoutTenantScope()->where('id', $shift->id)->first();
    $closedShift->closing_cash_counted = '999.0000'; // attempt to tamper with a closed shift
    expect(fn () => $closedShift->save())->toThrow(RuntimeException::class);

    // A new shift can still be opened on the same register now that the old one is closed.
    $reopened = $service->open($tenant->id, $register->id, '50.0000', $owner->id);
    expect($reopened->status)->toBe('open');
});
