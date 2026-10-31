<?php

use App\Models\ExpenseCategory;
use App\Models\Register;
use App\Models\User;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\ShiftService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

test('recording an expense against an open shift creates a matching cash movement that reduces expected cash', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-EXP-1', tenantName: 'Expense Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $category = ExpenseCategory::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'MISC', 'name' => 'Miscellaneous']);

    $shifts = app(ShiftService::class);
    $expenses = app(ExpenseService::class);

    $shift = $shifts->open($tenant->id, $register->id, '100.0000', $owner->id);
    $expense = $expenses->record($tenant->id, $category->id, '25.0000', 'cleaning supplies', $shift->id, $owner->id);

    expect($expense->cashier_shift_id)->toBe($shift->id);

    $closed = $shifts->close($tenant->id, $shift->id, '75.0000', $owner->id); // 100 - 25 expense = 75
    expect(bccomp($closed->expected_cash, '75.0000', 4))->toBe(0);
    expect(bccomp($closed->cash_difference, '0.0000', 4))->toBe(0);
});
