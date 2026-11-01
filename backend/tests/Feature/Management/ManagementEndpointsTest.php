<?php

use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Register;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Customers\CustomerLedgerService;
use App\Services\Finance\ShiftService;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Sales\SaleService;
use Illuminate\Support\Str;

beforeEach(function () {
    $onboarding = app(OnboardingService::class);
    $this->tenant = $onboarding->createTenant(
        shopCode: 'SHOP-MGT',
        tenantName: 'Management Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );

    $this->owner = $this->tenant->users()->where('username', 'owner')->first();
    $this->branch = $this->tenant->branches()->first();
    $this->warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $this->tenant->id)->first();
    $this->register = Register::withoutTenantScope()->where('tenant_id', $this->tenant->id)->first();
    $this->unit = Unit::withoutTenantScope()->create(['tenant_id' => $this->tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $this->priceList = PriceList::withoutTenantScope()->create(['tenant_id' => $this->tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true]);
    $this->cash = PaymentMethod::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'code' => 'cash',
        'name' => 'Cash',
        'type' => 'cash',
        'confirmation_mode' => 'instant',
        'allows_overpayment' => true,
        'is_active' => true,
    ]);

    $login = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $this->tenant->shop_code,
        'username' => 'owner',
        'password' => 'correct-password',
    ])->json('data');

    $this->auth = ['Authorization' => 'Bearer '.$login['access_token']];

    $this->shift = app(ShiftService::class)->open($this->tenant->id, $this->register->id, '1000.0000', $this->owner->id);
});

test('GET /sales lists sales for the authenticated tenant', function () {
    $product = Product::withoutTenantScope()->create(['tenant_id' => $this->tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'P1', 'name' => 'Product 1', 'base_unit_id' => $this->unit->id, 'is_active' => true]);
    ProductPrice::withoutTenantScope()->create(['tenant_id' => $this->tenant->id, 'price_list_id' => $this->priceList->id, 'product_id' => $product->id, 'unit_id' => $this->unit->id, 'variant_key' => 0, 'price' => '100.0000']);
    app(StockService::class)->recordMovement($this->tenant->id, ['warehouse_id' => $this->warehouse->id, 'product_id' => $product->id, 'direction' => 'in', 'quantity' => '10.0000', 'unit_cost' => '50.0000', 'source_type' => 'opening_stock']);

    $sale = app(SaleService::class)->create($this->tenant->id, [
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'register_id' => $this->register->id,
        'cashier_shift_id' => $this->shift->id,
        'price_list_id' => $this->priceList->id,
        'lines' => [['product_id' => $product->id, 'unit_id' => $this->unit->id, 'quantity' => '2.0000']],
        'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '200.0000']],
    ], $this->owner->id, true);

    $response = test()->withHeaders($this->auth)->getJson('/api/v1/sales');
    $response->assertOk();
    $response->assertJsonPath('success', true);
    $data = $response->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($sale->id)
        ->and($data[0]['grand_total'])->toBe('200.0000');
});

test('GET /sales/{id} returns full sale details', function () {
    $product = Product::withoutTenantScope()->create(['tenant_id' => $this->tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'P2', 'name' => 'Product 2', 'base_unit_id' => $this->unit->id, 'is_active' => true]);
    ProductPrice::withoutTenantScope()->create(['tenant_id' => $this->tenant->id, 'price_list_id' => $this->priceList->id, 'product_id' => $product->id, 'unit_id' => $this->unit->id, 'variant_key' => 0, 'price' => '50.0000']);
    app(StockService::class)->recordMovement($this->tenant->id, ['warehouse_id' => $this->warehouse->id, 'product_id' => $product->id, 'direction' => 'in', 'quantity' => '10.0000', 'unit_cost' => '25.0000', 'source_type' => 'opening_stock']);

    $sale = app(SaleService::class)->create($this->tenant->id, [
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'register_id' => $this->register->id,
        'cashier_shift_id' => $this->shift->id,
        'price_list_id' => $this->priceList->id,
        'lines' => [['product_id' => $product->id, 'unit_id' => $this->unit->id, 'quantity' => '1.0000']],
        'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '50.0000']],
    ], $this->owner->id, true);

    $response = test()->withHeaders($this->auth)->getJson("/api/v1/sales/{$sale->id}");
    $response->assertOk();
    $response->assertJsonPath('data.id', $sale->id);
    $response->assertJsonPath('data.items.0.name', 'Product 2');
});

test('POST /products creates a new product with barcode and price', function () {
    $response = test()->withHeaders($this->auth)->postJson('/api/v1/products', [
        'name' => 'New Test Product',
        'sku' => 'TEST-SKU-99',
        'base_unit_id' => $this->unit->id,
        'barcode' => '8901234567890',
        'price' => '150.0000',
        'price_list_id' => $this->priceList->id,
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.name', 'New Test Product');

    $this->assertDatabaseHas('products', ['tenant_id' => $this->tenant->id, 'sku' => 'TEST-SKU-99']);
    $this->assertDatabaseHas('barcodes', ['tenant_id' => $this->tenant->id, 'barcode' => '8901234567890']);
    $this->assertDatabaseHas('product_prices', ['tenant_id' => $this->tenant->id, 'price' => '150.0000']);
});

test('POST /customers creates a new customer and GET /customers/{id}/ledger returns ledger', function () {
    $createResponse = test()->withHeaders($this->auth)->postJson('/api/v1/customers', [
        'name' => 'Alice Customer',
        'phone' => '9876543210',
        'credit_limit' => '500.0000',
    ]);

    $createResponse->assertStatus(201);
    $customerId = $createResponse->json('data.id');

    // Add a ledger entry
    app(CustomerLedgerService::class)->recordEntry($this->tenant->id, $customerId, 'debit', '100.0000', 'sale', null, 'Test charge', $this->owner->id);

    $ledgerResponse = test()->withHeaders($this->auth)->getJson("/api/v1/customers/{$customerId}/ledger");
    $ledgerResponse->assertOk();
    expect($ledgerResponse->json('data'))->toHaveCount(1)
        ->and($ledgerResponse->json('data.0.amount'))->toBe('100.0000');
});

test('GET /inventory/balances returns warehouse stock balances', function () {
    $product = Product::withoutTenantScope()->create(['tenant_id' => $this->tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'STK-1', 'name' => 'Stocked Item', 'base_unit_id' => $this->unit->id, 'is_active' => true]);
    app(StockService::class)->recordMovement($this->tenant->id, ['warehouse_id' => $this->warehouse->id, 'product_id' => $product->id, 'direction' => 'in', 'quantity' => '25.0000', 'unit_cost' => '10.0000', 'source_type' => 'opening_stock']);

    $response = test()->withHeaders($this->auth)->getJson('/api/v1/inventory/balances');
    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['sku'])->toBe('STK-1')
        ->and($data[0]['quantity'])->toBe('25.0000')
        ->and($data[0]['total_value'])->toBe('250.0000');
});

test('GET /reports/summary returns key operational and financial figures', function () {
    $response = test()->withHeaders($this->auth)->getJson('/api/v1/reports/summary');
    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'gross_sales', 'net_sales', 'tax_collected', 'cogs', 'gross_profit', 'stock_valuation', 'outstanding',
        ],
    ]);
});
