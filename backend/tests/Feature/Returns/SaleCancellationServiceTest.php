<?php

use App\Models\ApprovalRequest;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\UserBranchAccess;
use App\Models\UserRole;
use App\Services\Customers\CustomerLedgerService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Str;

test('cancelling a sale within the cancel window reverses stock and marks it cancelled', function () {
    $fixture = setUpCompletedSaleFixture('SHOP-CANCEL-1', '4.0000');
    $balanceBefore = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->postJson("/api/v1/sales/{$fixture['sale']->id}/cancel", ['reason' => 'customer changed mind']);

    $response->assertStatus(201);

    $sale = Sale::withoutTenantScope()->find($fixture['sale']->id);
    expect($sale->status)->toBe('cancelled');

    $balanceAfter = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();
    expect(bccomp($balanceAfter->quantity, bcadd($balanceBefore->quantity, '4.0000', 4), 4))->toBe(0); // all 4 units restocked

    $movement = CashMovement::where('cashier_shift_id', $fixture['shift']->id)->where('type', 'cash_refund')->first();
    expect($movement)->not->toBeNull();
    expect(bccomp($movement->amount, '200.0000', 4))->toBe(0); // 4 * 50.0000
});

test('a sale cannot be cancelled after the cancel window has passed', function () {
    $fixture = setUpCompletedSaleFixture('SHOP-CANCEL-2', '2.0000');
    app(SettingsService::class)->set($fixture['tenant']->id, 'sales.cancel_window', 30);

    $sale = Sale::withoutTenantScope()->find($fixture['sale']->id);
    $sale->server_created_at = now()->subMinutes(45);
    $sale->save();

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->postJson("/api/v1/sales/{$fixture['sale']->id}/cancel", ['reason' => 'too late']);

    $response->assertStatus(409);
    expect($response->json('error.code'))->toBe('CANCEL_WINDOW_EXPIRED');

    expect(Sale::withoutTenantScope()->find($fixture['sale']->id)->status)->toBe('completed'); // untouched
});

test('a cashier without pos.sales.cancel cannot cancel a sale directly - it is blocked and an approval request is recorded', function () {
    $fixture = setUpCompletedSaleFixture('SHOP-CANCEL-3', '1.0000');

    $cashierRole = Role::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('code', 'cashier')->first();
    $cashier = User::withoutTenantScope()->create([
        'tenant_id' => $fixture['tenant']->id, 'uuid' => (string) Str::uuid(), 'username' => 'cashier1',
        'email' => 'cashier1@example.test', 'password' => 'cashier-pass', 'is_active' => true,
    ]);
    UserRole::create(['user_id' => $cashier->id, 'role_id' => $cashierRole->id]);
    UserBranchAccess::create(['user_id' => $cashier->id, 'branch_id' => $fixture['branch']->id]);

    $cashierLogin = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $fixture['tenant']->shop_code, 'username' => 'cashier1', 'password' => 'cashier-pass',
    ])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$cashierLogin['access_token'])
        ->postJson("/api/v1/sales/{$fixture['sale']->id}/cancel", ['reason' => 'test']);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('APPROVAL_REQUIRED');
    expect(ApprovalRequest::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('action_type', 'sale.cancel')->exists())->toBeTrue();
    expect(Sale::withoutTenantScope()->find($fixture['sale']->id)->status)->toBe('completed'); // untouched
});

test('cancelling a credit sale reverses the customer_ledger debit it created', function () {
    $fixture = setUpCompletedSaleFixture('SHOP-CANCEL-4', '2.0000');

    $customer = Customer::withoutTenantScope()->create([
        'tenant_id' => $fixture['tenant']->id, 'uuid' => (string) Str::uuid(), 'code' => 'CUST-1', 'name' => 'Credit Customer',
    ]);
    $credit = PaymentMethod::withoutTenantScope()->create([
        'tenant_id' => $fixture['tenant']->id, 'code' => 'credit', 'name' => 'Credit', 'confirmation_mode' => 'instant', 'allows_overpayment' => false,
    ]);

    $saleResponse = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/sales', [
            'branch_id' => $fixture['branch']->id, 'warehouse_id' => $fixture['warehouse']->id, 'register_id' => $fixture['register']->id,
            'price_list_id' => $fixture['priceList']->id, 'cashier_shift_id' => $fixture['shift']->id, 'customer_id' => $customer->id,
            'lines' => [['product_id' => $fixture['product']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '1.0000']],
            'payments' => [['payment_method_id' => $credit->id, 'amount' => '50.0000']],
        ])->assertStatus(201);

    $balanceBeforeCancel = app(CustomerLedgerService::class)->currentBalance($fixture['tenant']->id, $customer->id);
    expect(bccomp($balanceBeforeCancel, '50.0000', 4))->toBe(0);

    test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->postJson("/api/v1/sales/{$saleResponse->json('data.id')}/cancel", ['reason' => 'cancel credit sale'])
        ->assertStatus(201);

    $balanceAfterCancel = app(CustomerLedgerService::class)->currentBalance($fixture['tenant']->id, $customer->id);
    expect(bccomp($balanceAfterCancel, '0.0000', 4))->toBe(0); // fully reversed
});
