<?php

use App\Models\Branch;
use App\Models\Device;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Register;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Settings\SettingsService;
use App\Services\Sync\DeviceService;
use App\Services\Sync\SyncConflictService;
use Illuminate\Support\Str;

/**
 * section 13.2 "Offline gate (Phase 7)": every scenario here is tested
 * directly against POST /sync/push - the real decision point for every
 * one of the ten listed behaviours. `sales.shift_required` is turned off
 * for this fixture so the tests isolate sync semantics from Phase 3's
 * shift mechanics (both are already tested elsewhere).
 */
function setUpSyncFixture(string $shopCode, string $openingStock = '10.0000'): array
{
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: $shopCode, tenantName: 'Sync Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    app(SettingsService::class)->set($tenant->id, 'sales.shift_required', false);

    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-SYNC', 'name' => 'Sync Product',
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
        'direction' => 'in', 'quantity' => $openingStock, 'unit_cost' => '20.0000', 'source_type' => 'opening_stock',
    ]);
    $cash = PaymentMethod::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'confirmation_mode' => 'instant', 'allows_overpayment' => true,
    ]);

    $loginA = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password',
        'device_uuid' => 'DEVICE-A', 'device_name' => 'Tablet A',
    ])->json('data');
    $loginB = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password',
        'device_uuid' => 'DEVICE-B', 'device_name' => 'Tablet B',
    ])->json('data');

    $deviceA = Device::withoutTenantScope()->where('tenant_id', $tenant->id)->where('uuid', 'DEVICE-A')->first();
    $deviceB = Device::withoutTenantScope()->where('tenant_id', $tenant->id)->where('uuid', 'DEVICE-B')->first();

    return compact('tenant', 'branch', 'warehouse', 'register', 'owner', 'unit', 'product', 'priceList', 'cash', 'loginA', 'loginB', 'deviceA', 'deviceB');
}

function saleOperation(array $fixture, string $deviceUuid, string $quantity, ?string $deviceGrandTotal = null, ?string $clientCreatedAt = null, ?string $uuid = null): array
{
    return [
        'operation_uuid' => $uuid ?? (string) Str::uuid(),
        'type' => 'sale.create',
        'device_id' => $deviceUuid,
        'user_id' => $fixture['owner']->id,
        'client_created_at' => $clientCreatedAt ?? now()->toIso8601String(),
        'payload' => [
            'branch_id' => $fixture['branch']->id, 'warehouse_id' => $fixture['warehouse']->id, 'register_id' => $fixture['register']->id,
            'price_list_id' => $fixture['priceList']->id,
            'lines' => [['product_id' => $fixture['product']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => $quantity]],
            'payments' => [['payment_method_id' => $fixture['cash']->id, 'amount' => bcmul($quantity, '50.0000', 4)]],
            'device_grand_total' => $deviceGrandTotal,
        ],
    ];
}

test('selling offline then reconnecting: every queued sale is accepted once, with correct numbers', function () {
    $fixture = setUpSyncFixture('SHOP-SYNC-1');
    $ops = [
        saleOperation($fixture, 'DEVICE-A', '1.0000'),
        saleOperation($fixture, 'DEVICE-A', '2.0000'),
        saleOperation($fixture, 'DEVICE-A', '3.0000'),
    ];

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['loginA']['access_token'])
        ->postJson('/api/v1/sync/push', ['operations' => $ops]);

    $response->assertStatus(200);
    $results = $response->json('data.results');
    expect($results)->toHaveCount(3);
    foreach ($results as $i => $result) {
        expect($result['status'])->toBe('accepted');
        expect(bccomp($result['result']['grand_total'], bcmul((string) ($i + 1), '50.0000', 4), 4))->toBe(0);
    }

    expect(Sale::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->count())->toBe(3);
});

test('resubmitting the same operation UUID returns the earlier result - nothing new is created', function () {
    $fixture = setUpSyncFixture('SHOP-SYNC-2');
    $op = saleOperation($fixture, 'DEVICE-A', '1.0000');
    $auth = ['Authorization' => 'Bearer '.$fixture['loginA']['access_token']];

    $first = test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => [$op]]);
    $firstResult = $first->json('data.results.0');
    expect($firstResult['replay'])->toBeFalse();

    $second = test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => [$op]]);
    $secondResult = $second->json('data.results.0');

    expect($secondResult['replay'])->toBeTrue();
    expect($secondResult['result']['sale_id'])->toBe($firstResult['result']['sale_id']);
    expect(Sale::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->count())->toBe(1);
});

test('two devices selling the last unit offline: both are accepted, a STOCK_SHORTFALL conflict is flagged, nothing is lost', function () {
    $fixture = setUpSyncFixture('SHOP-SYNC-3', openingStock: '1.0000');
    $auth = ['Authorization' => 'Bearer '.$fixture['loginA']['access_token']];

    $opA = saleOperation($fixture, 'DEVICE-A', '1.0000');
    $opB = saleOperation($fixture, 'DEVICE-B', '1.0000');

    $response = test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => [$opA, $opB]]);
    $results = $response->json('data.results');

    expect($results[0]['status'])->toBe('accepted');
    expect($results[1]['status'])->toBe('accepted'); // neither sale is lost

    expect(Sale::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->count())->toBe(2);

    $shortfall = SyncConflict::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('type', 'STOCK_SHORTFALL')->first();
    expect($shortfall)->not->toBeNull();

    $balance = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();
    expect(bccomp($balance->quantity, '-1.0000', 4))->toBe(0); // 1 opening - 1 - 1, allowed negative for this one case
});

test('a server price change while offline is reconciled: the committed sale uses the server total, and the difference is recorded as a conflict', function () {
    $fixture = setUpSyncFixture('SHOP-SYNC-4');
    $auth = ['Authorization' => 'Bearer '.$fixture['loginA']['access_token']];

    // The device computed its total from a stale (pre-change) snapshot price of 40.0000/unit.
    $op = saleOperation($fixture, 'DEVICE-A', '1.0000', deviceGrandTotal: '40.0000');

    $response = test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => [$op]]);
    $result = $response->json('data.results.0');

    expect($result['status'])->toBe('accepted');
    expect(bccomp($result['result']['grand_total'], '50.0000', 4))->toBe(0); // real current server price wins, never the stale device price

    $conflict = SyncConflict::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('type', 'PRICE_MISMATCH')->first();
    expect($conflict)->not->toBeNull();
    expect(bccomp($conflict->details['device_total'], '40.0000', 4))->toBe(0);
    expect(bccomp($conflict->details['server_total'], '50.0000', 4))->toBe(0);
});

test('a revoked device: operations created BEFORE revocation are accepted, operations created AFTER are rejected for review', function () {
    $fixture = setUpSyncFixture('SHOP-SYNC-5');
    $auth = ['Authorization' => 'Bearer '.$fixture['loginA']['access_token']];

    $beforeRevocation = now()->subMinutes(30);
    app(DeviceService::class)->revoke($fixture['tenant']->id, $fixture['deviceB']->id, $fixture['owner']->id);

    $preOp = saleOperation($fixture, 'DEVICE-B', '1.0000', clientCreatedAt: $beforeRevocation->toIso8601String());
    $postOp = saleOperation($fixture, 'DEVICE-B', '1.0000', clientCreatedAt: now()->toIso8601String());

    $response = test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => [$preOp, $postOp]]);
    $results = $response->json('data.results');

    expect($results[0]['status'])->toBe('accepted');
    expect($results[1]['status'])->toBe('rejected');
    expect($results[1]['reject_reason'])->toBe('DEVICE_REVOKED');

    expect(Sale::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->count())->toBe(1);
});

test('an expired/suspended tenant: offline sales are held for review, never discarded', function () {
    $fixture = setUpSyncFixture('SHOP-SYNC-6');
    $auth = ['Authorization' => 'Bearer '.$fixture['loginA']['access_token']];

    $fixture['tenant']->status = 'suspended';
    $fixture['tenant']->save();

    $op = saleOperation($fixture, 'DEVICE-A', '1.0000');
    $response = test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => [$op]]);
    $result = $response->json('data.results.0');

    expect($result['status'])->toBe('held');
    expect(Sale::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->count())->toBe(0);

    // "never discarded" - the operation itself still exists, visible for review.
    expect(SyncOperation::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('uuid', $op['operation_uuid'])->exists())->toBeTrue();
});

test('device-scoped offline numbering: each device has its own independent, never-repeating sequence', function () {
    $fixture = setUpSyncFixture('SHOP-SYNC-7', openingStock: '100.0000');
    $auth = ['Authorization' => 'Bearer '.$fixture['loginA']['access_token']];

    test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => [saleOperation($fixture, 'DEVICE-A', '1.0000')]]);
    test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => [saleOperation($fixture, 'DEVICE-A', '1.0000')]]);
    $thirdA = test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => [saleOperation($fixture, 'DEVICE-A', '1.0000')]]);

    // Device B's very first sale starts its OWN sequence at 1, unaffected by device A already being at 3.
    $firstB = test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => [saleOperation($fixture, 'DEVICE-B', '1.0000')]]);

    $invoiceA3 = Sale::withoutTenantScope()->find($thirdA->json('data.results.0.result.sale_id'))->invoice->number;
    $invoiceB1 = Sale::withoutTenantScope()->find($firstB->json('data.results.0.result.sale_id'))->invoice->number;

    expect($invoiceA3)->toBe(3);
    expect($invoiceB1)->toBe(1);
});

test('a clock-skewed operation is flagged as a conflict but still accepted', function () {
    $fixture = setUpSyncFixture('SHOP-SYNC-8');
    $auth = ['Authorization' => 'Bearer '.$fixture['loginA']['access_token']];

    $op = saleOperation($fixture, 'DEVICE-A', '1.0000', clientCreatedAt: now()->subHours(3)->toIso8601String());
    $response = test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => [$op]]);

    expect($response->json('data.results.0.status'))->toBe('accepted');
    expect(SyncConflict::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('type', 'CLOCK_SKEW')->exists())->toBeTrue();
});

test('a large batch of offline operations all process correctly in one push', function () {
    $fixture = setUpSyncFixture('SHOP-SYNC-9', openingStock: '1000.0000');
    $auth = ['Authorization' => 'Bearer '.$fixture['loginA']['access_token']];

    $ops = array_map(fn () => saleOperation($fixture, 'DEVICE-A', '1.0000'), range(1, 20));
    $response = test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => $ops]);

    $statuses = array_column($response->json('data.results'), 'status');
    expect($statuses)->toEqual(array_fill(0, 20, 'accepted'));
    expect(Sale::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->count())->toBe(20);
});

test('rejected and held operations stay visible via GET /sync/operations until someone resolves the underlying conflict', function () {
    $fixture = setUpSyncFixture('SHOP-SYNC-10');
    $auth = ['Authorization' => 'Bearer '.$fixture['loginA']['access_token']];

    // Force a rejection: an unregistered device.
    $badOp = saleOperation($fixture, 'DEVICE-UNKNOWN', '1.0000');
    test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => [$badOp]]);

    $rejected = test()->withHeaders($auth)->getJson('/api/v1/sync/operations?status=rejected');
    expect(collect($rejected->json('data'))->pluck('uuid'))->toContain($badOp['operation_uuid']);

    // A price-mismatch conflict stays open until explicitly resolved.
    $mismatchOp = saleOperation($fixture, 'DEVICE-A', '1.0000', deviceGrandTotal: '1.0000');
    test()->withHeaders($auth)->postJson('/api/v1/sync/push', ['operations' => [$mismatchOp]]);

    $conflictsBefore = app(SyncConflictService::class)->listOpen($fixture['tenant']->id);
    expect($conflictsBefore)->not->toBeEmpty();

    $conflict = $conflictsBefore->first();
    app(SyncConflictService::class)->resolve($fixture['tenant']->id, $conflict->id, $fixture['owner']->id);

    $conflictsAfter = app(SyncConflictService::class)->listOpen($fixture['tenant']->id);
    expect($conflictsAfter->pluck('id'))->not->toContain($conflict->id);
});
