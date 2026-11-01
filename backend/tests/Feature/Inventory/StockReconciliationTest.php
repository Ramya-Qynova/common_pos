<?php

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\StockReconciliationService;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-REC-'.uniqid(),
        tenantName: 'Reconciliation Test Shop',
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

    $this->warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $this->tenant->id)->first();

    $this->productA = Product::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'uuid' => (string) Str::uuid(),
        'sku' => 'REC-PROD-A',
        'name' => 'Reconciliation Product A',
        'base_unit_id' => $this->unit->id,
    ]);

    $this->productB = Product::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'uuid' => (string) Str::uuid(),
        'sku' => 'REC-PROD-B',
        'name' => 'Reconciliation Product B',
        'base_unit_id' => $this->unit->id,
    ]);

    // Initial stock:
    // Product A has 50 units @ 10.0000
    // Product B has 20 units @ 30.0000
    $stock = app(StockService::class);
    $stock->recordMovement($this->tenant->id, [
        'warehouse_id' => $this->warehouse->id,
        'product_id' => $this->productA->id,
        'direction' => 'in',
        'quantity' => '50.0000',
        'unit_cost' => '10.0000',
        'source_type' => 'opening_stock',
    ]);
    $stock->recordMovement($this->tenant->id, [
        'warehouse_id' => $this->warehouse->id,
        'product_id' => $this->productB->id,
        'direction' => 'in',
        'quantity' => '20.0000',
        'unit_cost' => '30.0000',
        'source_type' => 'opening_stock',
    ]);
});

test('start reconciliation snapshots current system quantity and average cost', function () {
    $service = app(StockReconciliationService::class);

    $rec = $service->start($this->tenant->id, $this->warehouse->id, $this->owner->id);

    expect($rec->status)->toBe('in_progress');
    expect($rec->items)->toHaveCount(2);

    $itemA = $rec->items->firstWhere('product_id', $this->productA->id);
    expect($itemA->system_quantity)->toBe('50.0000');
    expect($itemA->unit_cost)->toBe('10.0000');
    expect($itemA->counted_quantity)->toBeNull();

    $itemB = $rec->items->firstWhere('product_id', $this->productB->id);
    expect($itemB->system_quantity)->toBe('20.0000');
    expect($itemB->unit_cost)->toBe('30.0000');
});

test('submit counts calculates differences and completion aligns stock balances', function () {
    $service = app(StockReconciliationService::class);

    $rec = $service->start($this->tenant->id, $this->warehouse->id, $this->owner->id);
    $itemA = $rec->items->firstWhere('product_id', $this->productA->id);
    $itemB = $rec->items->firstWhere('product_id', $this->productB->id);

    // Physical count:
    // Product A: 55 units found (+5 surplus)
    // Product B: 18 units found (-2 deficit)
    $service->submitCounts($this->tenant->id, $rec->id, [
        ['item_id' => $itemA->id, 'counted_quantity' => '55.0000'],
        ['item_id' => $itemB->id, 'counted_quantity' => '18.0000'],
    ], $this->owner->id);

    $itemA->refresh();
    expect(bcadd((string) $itemA->difference_quantity, '0', 4))->toBe('5.0000');
    expect(bcadd((string) $itemA->variance_value, '0', 4))->toBe('50.0000'); // 5 * 10

    $itemB->refresh();
    expect(bcadd((string) $itemB->difference_quantity, '0', 4))->toBe('-2.0000');
    expect(bcadd((string) $itemB->variance_value, '0', 4))->toBe('-60.0000'); // -2 * 30

    // Complete reconciliation
    $completed = $service->complete($this->tenant->id, $rec->id, $this->owner->id);
    expect($completed->status)->toBe('completed');
    expect($completed->completed_at)->not->toBeNull();

    // Verify stock balances now match counted quantities exactly
    $balanceA = StockBalance::withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->where('product_id', $this->productA->id)
        ->first();
    expect(bcadd((string) $balanceA->quantity, '0', 4))->toBe('55.0000');

    $balanceB = StockBalance::withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->where('product_id', $this->productB->id)
        ->first();
    expect(bcadd((string) $balanceB->quantity, '0', 4))->toBe('18.0000');
});

test('reconciliation can be cancelled if not completed', function () {
    $service = app(StockReconciliationService::class);

    $rec = $service->start($this->tenant->id, $this->warehouse->id, $this->owner->id);
    $cancelled = $service->cancel($this->tenant->id, $rec->id, $this->owner->id, 'Mistake in warehouse selection');

    expect($cancelled->status)->toBe('cancelled');

    expect(fn () => $service->complete($this->tenant->id, $rec->id, $this->owner->id))
        ->toThrow(InvalidArgumentException::class, 'Cannot complete reconciliation with status: cancelled');
});

test('HTTP API allows full stock reconciliation cycle', function () {
    $login = $this->postJson('/api/v1/auth/login', [
        'shop_code' => $this->tenant->shop_code,
        'username' => 'owner',
        'password' => 'password-1234',
    ])->json('data');
    $token = $login['access_token'];

    // Start reconciliation
    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-reconciliations', [
            'warehouse_id' => $this->warehouse->id,
            'notes' => 'Month-end audit',
        ]);

    $res->assertStatus(201);
    $recId = $res->json('data.id');

    // Fetch details to get item IDs
    $showRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/stock-reconciliations/{$recId}");
    $showRes->assertStatus(200);

    $items = $showRes->json('data.items');
    $firstItemId = $items[0]['id'];

    // Submit counts
    $countsRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/stock-reconciliations/{$recId}/submit-counts", [
            'counts' => [
                ['item_id' => $firstItemId, 'counted_quantity' => '60.0000'],
            ],
        ]);
    $countsRes->assertStatus(200);

    // Complete
    $compRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/stock-reconciliations/{$recId}/complete");
    $compRes->assertStatus(200);
    expect($compRes->json('data.status'))->toBe('completed');
});
