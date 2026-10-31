<?php

use App\Models\ApprovalRequest;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Register;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserBranchAccess;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\Finance\ShiftService;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

/**
 * Builds a tenant with one priced, stocked product and returns everything
 * a sale request needs, plus a logged-in owner's access token.
 */
function setUpSaleFixture(string $shopCode): array
{
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: $shopCode, tenantName: 'Sale Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );

    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-SALE', 'name' => 'Sale Product',
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
        'direction' => 'in', 'quantity' => '10.0000', 'unit_cost' => '20.0000', 'source_type' => 'opening_stock',
    ]);

    $cash = PaymentMethod::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'confirmation_mode' => 'instant', 'allows_overpayment' => true,
    ]);

    $login = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password',
    ])->json('data');

    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $shift = app(ShiftService::class)->open($tenant->id, $register->id, '0.0000', $owner->id);

    return compact('tenant', 'branch', 'warehouse', 'register', 'unit', 'product', 'priceList', 'cash', 'login', 'shift');
}

function baseSalePayload(array $fixture, string $quantity = '2.0000'): array
{
    return [
        'branch_id' => $fixture['branch']->id, 'warehouse_id' => $fixture['warehouse']->id,
        'register_id' => $fixture['register']->id, 'price_list_id' => $fixture['priceList']->id,
        'cashier_shift_id' => $fixture['shift']->id,
        'lines' => [
            ['product_id' => $fixture['product']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => $quantity],
        ],
        'payments' => [
            ['payment_method_id' => $fixture['cash']->id, 'amount' => bcmul($quantity, '50.0000', 4)],
        ],
    ];
}

test('a sale is created with server-resolved prices, moves real stock, and generates an invoice', function () {
    $fixture = setUpSaleFixture('SHOP-SALE-1');

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/sales', baseSalePayload($fixture));

    $response->assertStatus(201);
    expect($response->json('data.grand_total'))->toBe('100.0000'); // 2 * 50, no tax group on this product
    expect($response->json('data.invoice'))->not->toBeEmpty();

    $balance = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();
    expect(bccomp($balance->quantity, '8.0000', 4))->toBe(0); // 10 opening - 2 sold
});

test('server ignores a client-submitted price override - the stored total always comes from the server-side price lookup', function () {
    $fixture = setUpSaleFixture('SHOP-SALE-2');

    $payload = baseSalePayload($fixture, '1.0000');
    // Tamper: try to make the line (and the payment) look like it's for 1.00 instead of the real 50.00 price.
    $payload['lines'][0]['unit_price'] = '1.0000';
    $payload['grand_total'] = '1.0000';
    $payload['payments'][0]['amount'] = '50.0000'; // payment must still cover the REAL total to succeed

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/sales', $payload);

    $response->assertStatus(201);
    expect($response->json('data.grand_total'))->toBe('50.0000'); // the real price-list price, not the tampered 1.00
    // SQLite (test connection) normalizes "50.0000" to "50" on the reload
    // triggered by ->load() in SaleService - a storage-format quirk, not a
    // precision loss (see docs/DECISIONS.md D-0013/D-0014 for the same
    // issue elsewhere); compare numeric value rather than exact string.
    expect(bccomp((string) $response->json('data.items.0.unit_price'), '50.0000', 4))->toBe(0);
});

test('a failed sale (insufficient stock) leaves nothing behind - no sale, no invoice, no stock movement', function () {
    $fixture = setUpSaleFixture('SHOP-SALE-3');

    $payload = baseSalePayload($fixture, '999.0000'); // far more than the 10 units in stock
    $payload['payments'][0]['amount'] = bcmul('999.0000', '50.0000', 4);

    $beforeSales = Sale::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->count();
    $beforeLedger = StockLedger::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->count();

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/sales', $payload);

    $response->assertStatus(409);
    expect($response->json('error.code'))->toBe('INSUFFICIENT_STOCK');

    expect(Sale::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->count())->toBe($beforeSales);
    expect(StockLedger::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->count())->toBe($beforeLedger);

    $balance = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();
    expect(bccomp($balance->quantity, '10.0000', 4))->toBe(0); // untouched
});

test('the same Idempotency-Key with the same payload replays the original sale instead of creating a second one', function () {
    $fixture = setUpSaleFixture('SHOP-SALE-4');
    $key = (string) Str::uuid();
    $payload = baseSalePayload($fixture);

    $first = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/sales', $payload);
    $first->assertStatus(201);

    $second = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/sales', $payload);

    $second->assertStatus(201);
    $second->assertHeader('Idempotent-Replay', 'true');
    expect($second->json('data.id'))->toBe($first->json('data.id'));

    expect(Sale::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->count())->toBe(1);

    // Stock was only decremented ONCE - the replay did not re-run the sale.
    $balance = StockBalance::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('product_id', $fixture['product']->id)->first();
    expect(bccomp($balance->quantity, '8.0000', 4))->toBe(0);
});

test('the same Idempotency-Key with a DIFFERENT payload is rejected rather than silently applied', function () {
    $fixture = setUpSaleFixture('SHOP-SALE-5');
    $key = (string) Str::uuid();

    test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/sales', baseSalePayload($fixture, '1.0000'))
        ->assertStatus(201);

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/sales', baseSalePayload($fixture, '2.0000')); // different quantity = different payload

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('IDEMPOTENCY_KEY_REUSED');
});

test('a POST /sales request without an Idempotency-Key header is rejected', function () {
    $fixture = setUpSaleFixture('SHOP-SALE-6');

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->postJson('/api/v1/sales', baseSalePayload($fixture));

    $response->assertStatus(422);
});

test('a cashier without pos.sales.discount cannot complete a discounted sale - it is blocked and an approval request is recorded instead', function () {
    $fixture = setUpSaleFixture('SHOP-SALE-7');

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

    $payload = baseSalePayload($fixture, '1.0000');
    $payload['lines'][0]['line_discount'] = ['type' => 'percent', 'value' => '10.0000'];

    $beforeSales = Sale::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->count();

    $response = test()->withHeader('Authorization', 'Bearer '.$cashierLogin['access_token'])
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/sales', $payload);

    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('APPROVAL_REQUIRED');
    expect(Sale::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->count())->toBe($beforeSales);
    expect(ApprovalRequest::withoutTenantScope()->where('tenant_id', $fixture['tenant']->id)->where('action_type', 'sale.discount')->exists())->toBeTrue();
});

test('the owner (who has pos.sales.discount) can complete a discounted sale directly, no approval needed', function () {
    $fixture = setUpSaleFixture('SHOP-SALE-8');

    $payload = baseSalePayload($fixture, '1.0000');
    $payload['lines'][0]['line_discount'] = ['type' => 'percent', 'value' => '10.0000'];
    $payload['payments'][0]['amount'] = '45.0000'; // 50 - 10% = 45

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/sales', $payload);

    $response->assertStatus(201);
    expect($response->json('data.grand_total'))->toBe('45.0000');
});
