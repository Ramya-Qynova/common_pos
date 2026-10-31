<?php

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Onboarding\OnboardingService;
use App\Services\Purchasing\SupplierLedgerService;
use Illuminate\Support\Str;

/**
 * section 8 "Purchasing": "receiving is one transaction ... ledger rows at
 * received cost ... compute payable ... unpaid -> supplier ledger
 * payable." This closes D-0016/D-0022's open loop: a supplier_ledger debit
 * now has a real `purchase` source, not just opening balances.
 */
function setUpPurchaseFixture(string $shopCode): array
{
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: $shopCode, tenantName: 'Purchase Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );

    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-PUR', 'name' => 'Purchase Product',
        'base_unit_id' => $unit->id, 'is_active' => true,
    ]);
    $supplier = Supplier::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'SUP-REC', 'name' => 'Receiving Supplier']);

    $login = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password',
    ])->json('data');

    return compact('tenant', 'warehouse', 'unit', 'product', 'supplier', 'login');
}

test('receiving a purchase moves real stock in at the received cost and posts a supplier_ledger debit', function () {
    $fixture = setUpPurchaseFixture('SHOP-PUR-1');
    $auth = ['Authorization' => 'Bearer '.$fixture['login']['access_token']];

    $response = test()->withHeaders($auth)->postJson('/api/v1/purchases', [
        'supplier_id' => $fixture['supplier']->id, 'warehouse_id' => $fixture['warehouse']->id,
        'items' => [
            ['product_id' => $fixture['product']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '10.0000', 'unit_cost' => '15.0000', 'tax_amount' => '7.5000'],
        ],
    ]);

    $response->assertStatus(201);
    expect(bccomp($response->json('data.grand_total'), '157.5000', 4))->toBe(0); // 10*15 + 7.5

    $balance = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();
    expect(bccomp($balance->quantity, '10.0000', 4))->toBe(0);
    expect(bccomp($balance->average_cost, '15.0000', 4))->toBe(0);

    $ledgerBalance = app(SupplierLedgerService::class)->currentBalance($fixture['tenant']->id, $fixture['supplier']->id);
    expect(bccomp($ledgerBalance, '157.5000', 4))->toBe(0);

    $ledgerEntry = SupplierLedger::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('supplier_id', $fixture['supplier']->id)->first();
    expect($ledgerEntry->source_type)->toBe('purchase');
});

test('a purchase order is marked partially_received then received as matching purchases come in', function () {
    $fixture = setUpPurchaseFixture('SHOP-PUR-2');
    $auth = ['Authorization' => 'Bearer '.$fixture['login']['access_token']];

    $po = test()->withHeaders($auth)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $fixture['supplier']->id, 'warehouse_id' => $fixture['warehouse']->id,
        'items' => [['product_id' => $fixture['product']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '10.0000', 'unit_cost' => '15.0000']],
    ])->assertStatus(201);
    $poId = $po->json('data.id');

    test()->withHeaders($auth)->postJson("/api/v1/purchase-orders/{$poId}/order")->assertStatus(200);

    // Receive half.
    test()->withHeaders($auth)->postJson('/api/v1/purchases', [
        'supplier_id' => $fixture['supplier']->id, 'warehouse_id' => $fixture['warehouse']->id, 'purchase_order_id' => $poId,
        'items' => [['product_id' => $fixture['product']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '5.0000', 'unit_cost' => '15.0000']],
    ])->assertStatus(201);

    expect(PurchaseOrder::withoutTenantScope()->find($poId)->status)->toBe('partially_received');

    // Receive the rest.
    test()->withHeaders($auth)->postJson('/api/v1/purchases', [
        'supplier_id' => $fixture['supplier']->id, 'warehouse_id' => $fixture['warehouse']->id, 'purchase_order_id' => $poId,
        'items' => [['product_id' => $fixture['product']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '5.0000', 'unit_cost' => '15.0000']],
    ])->assertStatus(201);

    expect(PurchaseOrder::withoutTenantScope()->find($poId)->status)->toBe('received');
});

test('a purchase return cannot exceed what was received, and reverses stock + posts a supplier credit', function () {
    $fixture = setUpPurchaseFixture('SHOP-PUR-3');
    $auth = ['Authorization' => 'Bearer '.$fixture['login']['access_token']];

    $purchase = test()->withHeaders($auth)->postJson('/api/v1/purchases', [
        'supplier_id' => $fixture['supplier']->id, 'warehouse_id' => $fixture['warehouse']->id,
        'items' => [['product_id' => $fixture['product']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '10.0000', 'unit_cost' => '15.0000']],
    ])->assertStatus(201);
    $purchaseItem = PurchaseItem::where('purchase_id', $purchase->json('data.id'))->first();

    // Over-return is rejected.
    $overReturn = test()->withHeaders($auth)->postJson("/api/v1/purchases/{$purchase->json('data.id')}/returns", [
        'items' => [['purchase_item_id' => $purchaseItem->id, 'quantity' => '11.0000']],
    ]);
    $overReturn->assertStatus(409);
    expect($overReturn->json('error.code'))->toBe('PURCHASE_RETURN_EXCEEDS_RECEIVED');

    $balanceBefore = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();
    $ledgerBefore = app(SupplierLedgerService::class)->currentBalance($fixture['tenant']->id, $fixture['supplier']->id);

    $goodReturn = test()->withHeaders($auth)->postJson("/api/v1/purchases/{$purchase->json('data.id')}/returns", [
        'items' => [['purchase_item_id' => $purchaseItem->id, 'quantity' => '4.0000']],
    ]);
    $goodReturn->assertStatus(201);

    $balanceAfter = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();
    expect(bccomp($balanceAfter->quantity, bcsub($balanceBefore->quantity, '4.0000', 4), 4))->toBe(0);

    $ledgerAfter = app(SupplierLedgerService::class)->currentBalance($fixture['tenant']->id, $fixture['supplier']->id);
    expect(bccomp($ledgerAfter, bcsub($ledgerBefore, '60.0000', 4), 4))->toBe(0); // 4 * 15.0000 credited back
});
