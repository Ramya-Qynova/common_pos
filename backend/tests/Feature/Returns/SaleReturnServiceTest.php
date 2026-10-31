<?php

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Register;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Finance\ShiftService;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Str;

/**
 * Same shape as SaleCreationTest's setUpSaleFixture, plus: completes one
 * real sale via the HTTP API so every return/cancel test below works
 * against a genuine, server-priced, server-costed sale - never a hand-built
 * fixture row.
 */
function setUpCompletedSaleFixture(string $shopCode, string $quantity = '5.0000'): array
{
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: $shopCode, tenantName: 'Return Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );

    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-RET', 'name' => 'Return Product',
        'base_unit_id' => $unit->id, 'is_active' => true,
    ]);

    $priceList = PriceList::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true,
    ]);
    ProductPrice::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_key' => 0,
        'price_list_id' => $priceList->id, 'unit_id' => $unit->id, 'price' => '50.0000',
    ]);

    app(StockService::class)->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'in', 'quantity' => '100.0000', 'unit_cost' => '20.0000', 'source_type' => 'opening_stock',
    ]);

    $cash = PaymentMethod::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'confirmation_mode' => 'instant', 'allows_overpayment' => true,
    ]);

    $login = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password',
    ])->json('data');

    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $shift = app(ShiftService::class)->open($tenant->id, $register->id, '0.0000', $owner->id);

    $saleResponse = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/sales', [
            'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'register_id' => $register->id,
            'price_list_id' => $priceList->id, 'cashier_shift_id' => $shift->id,
            'lines' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => $quantity]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => bcmul($quantity, '50.0000', 4)]],
        ]);
    $saleResponse->assertStatus(201);

    $sale = Sale::withoutTenantScope()->with('items')->find($saleResponse->json('data.id'));

    return compact('tenant', 'branch', 'warehouse', 'register', 'unit', 'product', 'priceList', 'cash', 'login', 'shift', 'sale');
}

test('a return cannot exceed the quantity actually sold', function () {
    $fixture = setUpCompletedSaleFixture('SHOP-RET-1', '5.0000');
    $saleItem = $fixture['sale']->items->first();

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->postJson("/api/v1/sales/{$fixture['sale']->id}/returns", [
            'items' => [['sale_item_id' => $saleItem->id, 'quantity' => '6.0000']], // more than the 5 sold
        ]);

    $response->assertStatus(409);
    expect($response->json('error.code'))->toBe('RETURN_EXCEEDS_SOLD');
});

test('two returns against the same sale item cannot together exceed what was sold - the second is blocked at completion time', function () {
    $fixture = setUpCompletedSaleFixture('SHOP-RET-2', '5.0000');
    $saleItem = $fixture['sale']->items->first();
    $auth = ['Authorization' => 'Bearer '.$fixture['login']['access_token']];

    // First return of 3 units: request -> complete (inspection is on by default, so inspect first).
    $first = test()->withHeaders($auth)->postJson("/api/v1/sales/{$fixture['sale']->id}/returns", [
        'items' => [['sale_item_id' => $saleItem->id, 'quantity' => '3.0000']],
    ])->assertStatus(201);
    $firstReturnId = $first->json('data.id');
    test()->withHeaders($auth)->postJson("/api/v1/returns/{$firstReturnId}/inspect")->assertStatus(200);
    test()->withHeaders($auth)->postJson("/api/v1/returns/{$firstReturnId}/complete")->assertStatus(201);

    // A second return requesting 3 more (3 + 3 = 6 > 5 sold) must be rejected - even though
    // each individual request is <= 5, together they would exceed what was sold.
    $second = test()->withHeaders($auth)->postJson("/api/v1/sales/{$fixture['sale']->id}/returns", [
        'items' => [['sale_item_id' => $saleItem->id, 'quantity' => '3.0000']],
    ]);
    $second->assertStatus(409);
    expect($second->json('error.code'))->toBe('RETURN_EXCEEDS_SOLD');
});

test('a completed return restocks resellable items and refund amount uses the ORIGINAL sale price, not a later price change', function () {
    $fixture = setUpCompletedSaleFixture('SHOP-RET-3', '5.0000');
    $saleItem = $fixture['sale']->items->first();
    $auth = ['Authorization' => 'Bearer '.$fixture['login']['access_token']];

    // Price changes AFTER the sale - the refund must not be affected by this.
    ProductPrice::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->update(['price' => '999.0000']);

    $balanceBefore = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();

    $return = test()->withHeaders($auth)->postJson("/api/v1/sales/{$fixture['sale']->id}/returns", [
        'items' => [['sale_item_id' => $saleItem->id, 'quantity' => '2.0000', 'condition' => 'resellable']],
    ])->assertStatus(201);
    $returnId = $return->json('data.id');

    test()->withHeaders($auth)->postJson("/api/v1/returns/{$returnId}/inspect")->assertStatus(200);
    $complete = test()->withHeaders($auth)->postJson("/api/v1/returns/{$returnId}/complete", ['refund_method' => 'cash']);
    $complete->assertStatus(201);

    // 2 of 5 units returned, original line was 5 * 50.0000 = 250.0000 (no tax group) -> refund = 2/5 * 250.0000 = 100.0000, NOT 2 * 999.
    expect(bccomp($complete->json('data.amount'), '100.0000', 4))->toBe(0);
    expect($complete->json('data.status'))->toBe('completed');

    $balanceAfter = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();
    expect(bccomp($balanceAfter->quantity, bcadd($balanceBefore->quantity, '2.0000', 4), 4))->toBe(0);
});

test('a damaged-condition return does not restock - it is a write-off, not resellable inventory', function () {
    $fixture = setUpCompletedSaleFixture('SHOP-RET-4', '5.0000');
    $saleItem = $fixture['sale']->items->first();
    $auth = ['Authorization' => 'Bearer '.$fixture['login']['access_token']];

    $balanceBefore = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();

    $return = test()->withHeaders($auth)->postJson("/api/v1/sales/{$fixture['sale']->id}/returns", [
        'items' => [['sale_item_id' => $saleItem->id, 'quantity' => '1.0000', 'condition' => 'damaged']],
    ])->assertStatus(201);
    $returnId = $return->json('data.id');

    test()->withHeaders($auth)->postJson("/api/v1/returns/{$returnId}/inspect")->assertStatus(200);
    test()->withHeaders($auth)->postJson("/api/v1/returns/{$returnId}/complete", ['refund_method' => 'cash'])->assertStatus(201);

    $balanceAfter = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();
    expect(bccomp($balanceAfter->quantity, $balanceBefore->quantity, 4))->toBe(0); // unchanged - damaged stock is not resold
});

test('a cash refund is recorded as a cash_refund movement on the sale\'s open shift', function () {
    $fixture = setUpCompletedSaleFixture('SHOP-RET-5', '4.0000');
    $saleItem = $fixture['sale']->items->first();
    $auth = ['Authorization' => 'Bearer '.$fixture['login']['access_token']];

    $return = test()->withHeaders($auth)->postJson("/api/v1/sales/{$fixture['sale']->id}/returns", [
        'items' => [['sale_item_id' => $saleItem->id, 'quantity' => '1.0000']],
    ])->assertStatus(201);
    $returnId = $return->json('data.id');
    test()->withHeaders($auth)->postJson("/api/v1/returns/{$returnId}/inspect")->assertStatus(200);
    $complete = test()->withHeaders($auth)->postJson("/api/v1/returns/{$returnId}/complete", ['refund_method' => 'cash'])->assertStatus(201);

    $movement = CashMovement::where('cashier_shift_id', $fixture['shift']->id)->where('type', 'cash_refund')->first();
    expect($movement)->not->toBeNull();
    expect(bccomp($movement->amount, $complete->json('data.amount'), 4))->toBe(0);
});

test('skipping inspection: when returns.require_inspection is false, a requested return lands directly on inspected and can be completed in one more step', function () {
    $fixture = setUpCompletedSaleFixture('SHOP-RET-6', '3.0000');
    app(SettingsService::class)->set($fixture['tenant']->id, 'returns.require_inspection', false);
    $saleItem = $fixture['sale']->items->first();
    $auth = ['Authorization' => 'Bearer '.$fixture['login']['access_token']];

    $return = test()->withHeaders($auth)->postJson("/api/v1/sales/{$fixture['sale']->id}/returns", [
        'items' => [['sale_item_id' => $saleItem->id, 'quantity' => '1.0000']],
    ])->assertStatus(201);
    expect($return->json('data.status'))->toBe('inspected'); // not 'requested' - inspection was skipped

    test()->withHeaders($auth)->postJson("/api/v1/returns/{$return->json('data.id')}/complete", ['refund_method' => 'cash'])->assertStatus(201);
});
