<?php

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Inventory\StockTransferService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-TRF-'.uniqid(),
        tenantName: 'Transfer Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'password-1234',
        ownerEmail: 'owner@example.test',
    );

    $this->owner = $this->tenant->users()->first();

    $this->unit = Unit::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'code' => 'pcs',
        'name' => 'Pieces',
    ]);

    $branch = $this->tenant->branches()->first();

    $this->warehouseA = Warehouse::withoutTenantScope()->where('tenant_id', $this->tenant->id)->first();
    $this->warehouseB = Warehouse::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'uuid' => (string) Str::uuid(),
        'code' => 'WH-B-'.uniqid(),
        'name' => 'Destination Warehouse B',
        'is_active' => true,
    ]);

    $this->product = Product::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'uuid' => (string) Str::uuid(),
        'sku' => 'PROD-TRF-01',
        'name' => 'Transfer Product 1',
        'base_unit_id' => $this->unit->id,
    ]);

    // Give warehouse A initial stock: 100 units @ 20.0000
    app(StockService::class)->recordMovement($this->tenant->id, [
        'warehouse_id' => $this->warehouseA->id,
        'product_id' => $this->product->id,
        'direction' => 'in',
        'quantity' => '100.0000',
        'unit_cost' => '20.0000',
        'source_type' => 'opening_stock',
    ]);
});

test('transfer creation validates distinct warehouses and persists draft', function () {
    $service = app(StockTransferService::class);

    expect(fn () => $service->create(
        $this->tenant->id,
        $this->warehouseA->id,
        $this->warehouseA->id,
        [['product_id' => $this->product->id, 'quantity' => '10.0000']],
        $this->owner->id
    ))->toThrow(InvalidArgumentException::class, 'Source and destination warehouse cannot be the same.');

    $transfer = $service->create(
        $this->tenant->id,
        $this->warehouseA->id,
        $this->warehouseB->id,
        [['product_id' => $this->product->id, 'quantity' => '25.0000']],
        $this->owner->id,
        'Stock transfer note'
    );

    expect($transfer->status)->toBe('draft');
    expect($transfer->items)->toHaveCount(1);
    expect($transfer->items[0]->quantity_dispatched)->toBe('25.0000');
    expect($transfer->items[0]->quantity_received)->toBe('0.0000');
});

test('dispatching transfer decrements source warehouse and transitions to dispatched', function () {
    $service = app(StockTransferService::class);

    $transfer = $service->create(
        $this->tenant->id,
        $this->warehouseA->id,
        $this->warehouseB->id,
        [['product_id' => $this->product->id, 'quantity' => '25.0000']],
        $this->owner->id
    );

    $dispatched = $service->dispatch($this->tenant->id, $transfer->id, $this->owner->id);

    expect($dispatched->status)->toBe('dispatched');
    expect($dispatched->dispatched_at)->not->toBeNull();

    // Warehouse A balance should now be 75.0000
    $balanceA = StockBalance::withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('warehouse_id', $this->warehouseA->id)
        ->where('product_id', $this->product->id)
        ->first();

    expect(bcadd((string) $balanceA->quantity, '0', 4))->toBe('75.0000');

    // Warehouse B should not yet have received the stock
    $balanceB = StockBalance::withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('warehouse_id', $this->warehouseB->id)
        ->where('product_id', $this->product->id)
        ->first();

    expect($balanceB)->toBeNull();
});

test('receiving transfer increments destination warehouse with captured unit cost', function () {
    $service = app(StockTransferService::class);

    $transfer = $service->create(
        $this->tenant->id,
        $this->warehouseA->id,
        $this->warehouseB->id,
        [['product_id' => $this->product->id, 'quantity' => '25.0000']],
        $this->owner->id,
        null,
        autoDispatch: true
    );

    $received = $service->receive($this->tenant->id, $transfer->id, null, $this->owner->id);

    expect($received->status)->toBe('received');
    expect($received->received_at)->not->toBeNull();

    // Warehouse B now has 25 units at cost 20.0000
    $balanceB = StockBalance::withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('warehouse_id', $this->warehouseB->id)
        ->where('product_id', $this->product->id)
        ->first();

    expect(bcadd((string) $balanceB->quantity, '0', 4))->toBe('25.0000');
    expect(bcadd((string) $balanceB->average_cost, '0', 4))->toBe('20.0000');
});

test('receiving with discrepancy flags discrepancy and adjusts ledger accordingly', function () {
    $service = app(StockTransferService::class);

    $transfer = $service->create(
        $this->tenant->id,
        $this->warehouseA->id,
        $this->warehouseB->id,
        [['product_id' => $this->product->id, 'quantity' => '20.0000']],
        $this->owner->id,
        null,
        autoDispatch: true
    );

    $item = $transfer->items()->first();

    // Receive only 18 units (2 units lost in transit)
    $received = $service->receive($this->tenant->id, $transfer->id, [
        ['item_id' => $item->id, 'quantity_received' => '18.0000'],
    ], $this->owner->id);

    expect($received->status)->toBe('partially_received');

    $item->refresh();
    expect($item->quantity_received)->toBe('18.0000');
    expect($item->discrepancy_quantity)->toBe('2.0000');

    // Warehouse B should receive exactly 18 units
    $balanceB = StockBalance::withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('warehouse_id', $this->warehouseB->id)
        ->where('product_id', $this->product->id)
        ->first();

    expect(bcadd((string) $balanceB->quantity, '0', 4))->toBe('18.0000');

    // Trying to receive more than dispatched throws
    $transfer2 = $service->create(
        $this->tenant->id,
        $this->warehouseA->id,
        $this->warehouseB->id,
        [['product_id' => $this->product->id, 'quantity' => '10.0000']],
        $this->owner->id,
        null,
        autoDispatch: true
    );
    $item2 = $transfer2->items()->first();

    expect(fn () => $service->receive($this->tenant->id, $transfer2->id, [
        ['item_id' => $item2->id, 'quantity_received' => '15.0000'],
    ], $this->owner->id))->toThrow(InvalidArgumentException::class, 'cannot exceed dispatched quantity');
});

test('cancelling a dispatched transfer returns stock to source warehouse', function () {
    $service = app(StockTransferService::class);

    $transfer = $service->create(
        $this->tenant->id,
        $this->warehouseA->id,
        $this->warehouseB->id,
        [['product_id' => $this->product->id, 'quantity' => '30.0000']],
        $this->owner->id,
        null,
        autoDispatch: true
    );

    // Balance A was reduced to 70.0000
    $balanceA = StockBalance::withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('warehouse_id', $this->warehouseA->id)
        ->where('product_id', $this->product->id)
        ->first();
    expect(bcadd((string) $balanceA->quantity, '0', 4))->toBe('70.0000');

    $cancelled = $service->cancel($this->tenant->id, $transfer->id, $this->owner->id, 'Truck broke down');

    expect($cancelled->status)->toBe('cancelled');

    // Balance A should be restored back to 100.0000
    $balanceA->refresh();
    expect(bcadd((string) $balanceA->quantity, '0', 4))->toBe('100.0000');
});

test('HTTP API allows full stock transfer lifecycle', function () {
    $login = $this->postJson('/api/v1/auth/login', [
        'shop_code' => $this->tenant->shop_code,
        'username' => 'owner',
        'password' => 'password-1234',
    ])->json('data');
    $token = $login['access_token'];

    // Create via API
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-transfers', [
            'source_warehouse_id' => $this->warehouseA->id,
            'destination_warehouse_id' => $this->warehouseB->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '15.0000'],
            ],
            'notes' => 'API Transfer',
        ]);

    $response->assertStatus(201);
    $transferId = $response->json('data.id');

    // Dispatch via API
    $dispatchRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/stock-transfers/{$transferId}/dispatch");
    $dispatchRes->assertStatus(200);
    expect($dispatchRes->json('data.status'))->toBe('dispatched');

    // Receive via API
    $receiveRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/stock-transfers/{$transferId}/receive");
    $receiveRes->assertStatus(200);
    expect($receiveRes->json('data.status'))->toBe('received');

    // Show details
    $showRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/stock-transfers/{$transferId}");
    $showRes->assertStatus(200);
    expect($showRes->json('data.items.0.quantity_received'))->toBe('15.0000');
});
