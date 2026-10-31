<?php

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\TemplateUpdateService;
use Illuminate\Support\Str;

/**
 * section 8 "Stock operations": "opening stock/adjustment/damage/wastage
 * need inventory.adjust plus a mandatory reason." StockAdjustmentService
 * itself has been generic since Phase 2 (D-0028 extended it, this phase
 * just gives it its first HTTP route) - "wastage" is never a special case
 * in code, only a `type` string any tenant may pass.
 */
function setUpWastageFixture(string $shopCode, string $templateCode): array
{
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: $shopCode, tenantName: 'Wastage Test Shop', planCode: 'standard',
        templateCode: $templateCode, ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    app(TemplateUpdateService::class)->apply($tenant->id, $owner->id);

    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $unit = Unit::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-WASTE', 'name' => 'Wastage Product',
        'base_unit_id' => $unit->id, 'is_active' => true,
    ]);

    app(StockService::class)->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'in', 'quantity' => '20.0000', 'unit_cost' => '10.0000', 'source_type' => 'opening_stock',
    ]);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    return compact('tenant', 'warehouse', 'unit', 'product', 'login');
}

test('a wastage stock adjustment reduces the balance and is recorded on the ledger with type=wastage, on a grocery tenant', function () {
    $fixture = setUpWastageFixture('SHOP-WASTE-1', 'grocery');

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])->postJson('/api/v1/stock-adjustments', [
        'warehouse_id' => $fixture['warehouse']->id, 'type' => 'wastage', 'reason' => 'spoiled',
        'items' => [['product_id' => $fixture['product']->id, 'direction' => 'out', 'quantity' => '3.0000']],
    ]);

    $response->assertStatus(201);
    expect($response->json('data.type'))->toBe('wastage');

    $balance = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();
    expect(bccomp($balance->quantity, '17.0000', 4))->toBe(0);

    $ledger = StockLedger::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('source_type', 'wastage')->first();
    expect($ledger)->not->toBeNull();
    expect($ledger->reason)->toBe('spoiled');
});

test('the exact same wastage request works identically on a general_retail tenant - wastage is not grocery-gated', function () {
    $fixture = setUpWastageFixture('SHOP-WASTE-2', 'general_retail');

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])->postJson('/api/v1/stock-adjustments', [
        'warehouse_id' => $fixture['warehouse']->id, 'type' => 'wastage', 'reason' => 'past expiry',
        'items' => [['product_id' => $fixture['product']->id, 'direction' => 'out', 'quantity' => '1.0000']],
    ]);

    $response->assertStatus(201);
});

test('a stock adjustment without a reason is rejected', function () {
    $fixture = setUpWastageFixture('SHOP-WASTE-3', 'grocery');

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])->postJson('/api/v1/stock-adjustments', [
        'warehouse_id' => $fixture['warehouse']->id, 'type' => 'wastage', 'reason' => '',
        'items' => [['product_id' => $fixture['product']->id, 'direction' => 'out', 'quantity' => '1.0000']],
    ]);

    $response->assertStatus(422);
});
