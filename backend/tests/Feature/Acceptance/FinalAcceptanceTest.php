<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Register;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Finance\ShiftService;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

test('section 15 acceptance: Shop A cannot access Shop B by any route', function () {
    $onboarding = app(OnboardingService::class);
    $tenantA = $onboarding->createTenant(
        shopCode: 'ACC-A', tenantName: 'Shop A', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner_a', ownerPassword: 'password-a-123', ownerEmail: 'a@test.com',
    );
    $tenantB = $onboarding->createTenant(
        shopCode: 'ACC-B', tenantName: 'Shop B', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner_b', ownerPassword: 'password-b-123', ownerEmail: 'b@test.com',
    );

    $loginA = test()->postJson('/api/v1/auth/login', ['shop_code' => 'ACC-A', 'username' => 'owner_a', 'password' => 'password-a-123'])->json('data');
    $authA = ['Authorization' => 'Bearer '.$loginA['access_token']];

    $unitB = Unit::withoutTenantScope()->create(['tenant_id' => $tenantB->id, 'code' => 'pcs', 'name' => 'Pieces B']);
    $productB = Product::withoutTenantScope()->create([
        'tenant_id' => $tenantB->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-B', 'name' => 'Product B',
        'base_unit_id' => $unitB->id, 'is_active' => true,
    ]);

    // Shop A searches for products - must NOT see Shop B's product
    $searchResp = test()->withHeaders($authA)->getJson('/api/v1/products?search=Product+B')->assertStatus(200)->json('data');
    expect($searchResp)->toBeEmpty();

    // Shop A directly requests Shop B's product details - must be 404 (not found, not forbidden, G10)
    test()->withHeaders($authA)->getJson("/api/v1/products/{$productB->id}")->assertStatus(404);
});

test('section 15 acceptance: calls without permission are rejected', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'ACC-PERM', tenantName: 'Perm Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@test.com',
    );

    // Create a cashier user without reports or settings permissions
    $cashier = User::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'name' => 'Cashier',
        'username' => 'cashier', 'password' => 'cashier-pass-123', 'is_active' => true,
    ]);

    $loginCashier = test()->postJson('/api/v1/auth/login', ['shop_code' => 'ACC-PERM', 'username' => 'cashier', 'password' => 'cashier-pass-123'])->json('data');
    $authCashier = ['Authorization' => 'Bearer '.$loginCashier['access_token']];

    // Attempt to access reports without permission - must be 403 Forbidden
    test()->withHeaders($authCashier)->getJson('/api/v1/reports/summary')->assertStatus(403);

    // Attempt to configure webhooks without permission - must be 403 Forbidden
    test()->withHeaders($authCashier)->getJson('/api/v1/integrations/webhooks')->assertStatus(403);
});

test('section 15 acceptance: frontend prices and totals are completely ignored by the server (G1)', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'ACC-PRICE', tenantName: 'Price Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@test.com',
    );
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-PRC', 'name' => 'Price Product',
        'base_unit_id' => $unit->id, 'is_active' => true,
    ]);
    $priceList = PriceList::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true,
    ]);
    // Real server price is 100.0000
    ProductPrice::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_key' => 0,
        'price_list_id' => $priceList->id, 'unit_id' => $unit->id, 'price' => '100.0000',
    ]);
    app(StockService::class)->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'in', 'quantity' => '10.0000', 'unit_cost' => '50.0000', 'source_type' => 'opening_stock',
    ]);
    $cash = PaymentMethod::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'confirmation_mode' => 'instant', 'allows_overpayment' => true,
    ]);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => 'ACC-PRICE', 'username' => 'owner', 'password' => 'correct-password'])->json('data');
    $auth = ['Authorization' => 'Bearer '.$login['access_token']];
    $shift = app(ShiftService::class)->open($tenant->id, $register->id, '100.0000', $owner->id);

    // Client attempts to pass tampered price of 1.0000 and total of 1.0000
    $saleResp = test()->withHeaders($auth)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/sales', [
        'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'register_id' => $register->id,
        'price_list_id' => $priceList->id, 'cashier_shift_id' => $shift->id,
        'lines' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => '1.0000', 'unit_price' => '1.0000']],
        'payments' => [['payment_method_id' => $cash->id, 'amount' => '100.0000']],
    ])->assertStatus(201)->json('data');

    // Server must compute 100.0000 from authoritative price list, ignoring the 1.0000
    expect(bccomp($saleResp['grand_total'], '100.0000', 4))->toBe(0);
});

test('section 15 acceptance: same idempotency key creates no duplicate sale', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'ACC-IDEMP', tenantName: 'Idemp Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@test.com',
    );
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-IDM', 'name' => 'Idemp Product',
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
        'direction' => 'in', 'quantity' => '20.0000', 'unit_cost' => '20.0000', 'source_type' => 'opening_stock',
    ]);
    $cash = PaymentMethod::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'confirmation_mode' => 'instant', 'allows_overpayment' => true,
    ]);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => 'ACC-IDEMP', 'username' => 'owner', 'password' => 'correct-password'])->json('data');
    $auth = ['Authorization' => 'Bearer '.$login['access_token']];
    $shift = app(ShiftService::class)->open($tenant->id, $register->id, '100.0000', $owner->id);

    $idempotencyKey = (string) Str::uuid();

    // Call 1
    $resp1 = test()->withHeaders($auth)->withHeader('Idempotency-Key', $idempotencyKey)->postJson('/api/v1/sales', [
        'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'register_id' => $register->id,
        'price_list_id' => $priceList->id, 'cashier_shift_id' => $shift->id,
        'lines' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => '1.0000']],
        'payments' => [['payment_method_id' => $cash->id, 'amount' => '50.0000']],
    ])->assertStatus(201)->json('data');

    // Call 2 with identical key
    $resp2 = test()->withHeaders($auth)->withHeader('Idempotency-Key', $idempotencyKey)->postJson('/api/v1/sales', [
        'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'register_id' => $register->id,
        'price_list_id' => $priceList->id, 'cashier_shift_id' => $shift->id,
        'lines' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => '1.0000']],
        'payments' => [['payment_method_id' => $cash->id, 'amount' => '50.0000']],
    ])->assertStatus(201)->json('data');

    // Exactly one sale created in database
    expect(Sale::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);
    expect($resp1['id'])->toBe($resp2['id']);
});

test('section 15 acceptance: stock balance strictly equals the sum of its stock ledger entries (G5)', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'ACC-LEDGER', tenantName: 'Ledger Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@test.com',
    );
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-LDG', 'name' => 'Ledger Item',
        'base_unit_id' => $unit->id, 'is_active' => true,
    ]);

    $stockService = app(StockService::class);

    // Sequence of movements: In 100 -> Out 30 -> In 50 -> Out 40
    $stockService->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'in', 'quantity' => '100.0000', 'unit_cost' => '10.0000', 'source_type' => 'opening_stock',
    ]);
    $stockService->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'out', 'quantity' => '30.0000', 'unit_cost' => '10.0000', 'source_type' => 'sale',
    ]);
    $stockService->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'in', 'quantity' => '50.0000', 'unit_cost' => '12.0000', 'source_type' => 'purchase_receipt',
    ]);
    $stockService->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'out', 'quantity' => '40.0000', 'unit_cost' => '11.0000', 'source_type' => 'sale',
    ]);

    // Ledger sum: +100 -30 +50 -40 = 80.0000
    $inSum = StockLedger::withoutTenantScope()->where('tenant_id', $tenant->id)->where('direction', 'in')->sum('quantity');
    $outSum = StockLedger::withoutTenantScope()->where('tenant_id', $tenant->id)->where('direction', 'out')->sum('quantity');
    $ledgerExpected = bcsub((string) $inSum, (string) $outSum, 4);

    $balance = StockBalance::withoutTenantScope()
        ->where('tenant_id', $tenant->id)->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)
        ->first();

    expect(bccomp($balance->quantity, '80.0000', 4))->toBe(0);
    expect(bccomp($balance->quantity, $ledgerExpected, 4))->toBe(0);
});

test('section 15 acceptance: returns cannot exceed quantity sold', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'ACC-RET', tenantName: 'Return Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@test.com',
    );
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-RET', 'name' => 'Return Item',
        'base_unit_id' => $unit->id, 'is_active' => true,
    ]);
    $priceList = PriceList::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true,
    ]);
    ProductPrice::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_key' => 0,
        'price_list_id' => $priceList->id, 'unit_id' => $unit->id, 'price' => '20.0000',
    ]);
    app(StockService::class)->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'in', 'quantity' => '10.0000', 'unit_cost' => '10.0000', 'source_type' => 'opening_stock',
    ]);
    $cash = PaymentMethod::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'confirmation_mode' => 'instant', 'allows_overpayment' => true,
    ]);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => 'ACC-RET', 'username' => 'owner', 'password' => 'correct-password'])->json('data');
    $auth = ['Authorization' => 'Bearer '.$login['access_token']];
    $shift = app(ShiftService::class)->open($tenant->id, $register->id, '100.0000', $owner->id);

    // Sell 2 units
    $saleResp = test()->withHeaders($auth)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/sales', [
        'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'register_id' => $register->id,
        'price_list_id' => $priceList->id, 'cashier_shift_id' => $shift->id,
        'lines' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => '2.0000']],
        'payments' => [['payment_method_id' => $cash->id, 'amount' => '40.0000']],
    ])->assertStatus(201)->json('data');

    $sale = Sale::withoutTenantScope()->with('items')->find($saleResp['id']);
    $saleItem = $sale->items->first();

    // Attempt to return 3 units (more than 2 sold)
    test()->withHeaders($auth)->postJson("/api/v1/sales/{$sale->id}/returns", [
        'items' => [['sale_item_id' => $saleItem->id, 'quantity' => '3.0000', 'condition' => 'resellable']],
    ])->assertStatus(409);
});

test('section 15 acceptance: every critical change is recorded in the audit log', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'ACC-AUDIT', tenantName: 'Audit Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@test.com',
    );
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    // Open cashier shift - triggers audit log
    app(ShiftService::class)->open($tenant->id, $register->id, '100.0000', $owner->id);

    $audit = AuditLog::where('tenant_id', $tenant->id)->where('action', 'shift.opened')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->user_id)->toBe($owner->id);
    expect($audit->entity_type)->toBe('CashierShift');
});
