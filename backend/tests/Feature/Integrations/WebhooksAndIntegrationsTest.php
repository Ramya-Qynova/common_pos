<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Register;
use App\Models\TaxGroup;
use App\Models\TaxGroupItem;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Customers\CustomerLedgerService;
use App\Services\Finance\ShiftService;
use App\Services\Integrations\ApiKeyService;
use App\Services\Integrations\WebhookService;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('webhooks lifecycle: registration, event dispatch with HMAC signature, test ping, and retry', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-INT-1', tenantName: 'Integration Test Shop 1', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');
    $auth = ['Authorization' => 'Bearer '.$login['access_token']];

    // Mock external HTTP endpoint
    Http::fake([
        'https://example.com/webhook-receiver' => Http::response(['received' => true], 200),
        'https://example.com/failing-receiver' => Http::response('Server Error', 500),
    ]);

    // 1. Register a webhook
    $secret = 'test-secret-key-32-chars-long!!';
    $createResp = test()->withHeaders($auth)->postJson('/api/v1/integrations/webhooks', [
        'name' => 'ERP Sales Webhook',
        'url' => 'https://example.com/webhook-receiver',
        'secret' => $secret,
        'events' => ['sale.created', 'inventory.adjusted'],
        'is_active' => true,
    ])->assertStatus(201)->json('data');

    $webhookId = $createResp['id'];
    expect($createResp['name'])->toBe('ERP Sales Webhook');
    expect($createResp['events'])->toContain('sale.created');

    // 2. Test ping endpoint
    $pingResp = test()->withHeaders($auth)->postJson("/api/v1/integrations/webhooks/{$webhookId}/test")
        ->assertStatus(200)
        ->json('data');

    expect($pingResp['status'])->toBe('delivered');
    expect($pingResp['response_status'])->toBe(200);

    // Verify HMAC-SHA256 signature was transmitted
    Http::assertSent(function (Request $request) use ($secret) {
        $signature = $request->header('X-POS-Signature')[0] ?? '';
        $timestamp = $request->header('X-POS-Timestamp')[0] ?? '';
        $body = $request->body();
        $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $signature === $expected && $request->header('X-POS-Event')[0] === 'ping';
    });

    // 3. Dispatch an event via WebhookService
    $deliveries = app(WebhookService::class)->dispatch($tenant->id, 'sale.created', [
        'invoice_number' => 'INV-TEST-001',
        'grand_total' => '250.0000',
    ]);
    expect($deliveries)->toHaveCount(1);
    expect($deliveries[0]->status)->toBe('delivered');

    // 4. Update webhook to a failing URL and retry
    test()->withHeaders($auth)->putJson("/api/v1/integrations/webhooks/{$webhookId}", [
        'url' => 'https://example.com/failing-receiver',
    ])->assertStatus(200);

    $failDeliveries = app(WebhookService::class)->dispatch($tenant->id, 'sale.created', ['test' => true]);
    expect($failDeliveries[0]->status)->toBe('failed');
    expect($failDeliveries[0]->response_status)->toBe(500);

    // Retry delivery
    $retryResp = test()->withHeaders($auth)->postJson("/api/v1/integrations/webhooks/deliveries/{$failDeliveries[0]->id}/retry")
        ->assertStatus(200)
        ->json('data');
    expect($retryResp['attempt'])->toBe(2);

    // 5. Fetch deliveries list
    $listResp = test()->withHeaders($auth)->getJson("/api/v1/integrations/webhooks/{$webhookId}/deliveries")
        ->assertStatus(200)
        ->json('data');
    expect(count($listResp))->toBeGreaterThanOrEqual(3);

    // 6. Delete webhook
    test()->withHeaders($auth)->deleteJson("/api/v1/integrations/webhooks/{$webhookId}")
        ->assertStatus(200);
});

test('api keys management: generate, validate token hash, and revoke', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-INT-2', tenantName: 'Integration Test Shop 2', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');
    $auth = ['Authorization' => 'Bearer '.$login['access_token']];

    // Generate API key
    $createResp = test()->withHeaders($auth)->postJson('/api/v1/integrations/api-keys', [
        'name' => 'Warehouse Automation Service',
        'permissions' => ['inventory.view', 'inventory.adjust'],
    ])->assertStatus(201)->json('data');

    expect($createResp['plain_key'])->toStartWith('pos_live_');
    $keyId = $createResp['api_key']['id'];
    $plainKey = $createResp['plain_key'];

    // Validate key via ApiKeyService
    $apiKeyService = app(ApiKeyService::class);
    $validated = $apiKeyService->validate($plainKey);
    expect($validated)->not->toBeNull();
    expect($validated->id)->toBe($keyId);
    expect($validated->last_used_at)->not->toBeNull();

    // List API keys
    $list = test()->withHeaders($auth)->getJson('/api/v1/integrations/api-keys')
        ->assertStatus(200)
        ->json('data');
    expect($list)->toHaveCount(1);
    expect($list[0]['name'])->toBe('Warehouse Automation Service');

    // Revoke API key
    test()->withHeaders($auth)->deleteJson("/api/v1/integrations/api-keys/{$keyId}")
        ->assertStatus(200);

    // Validating revoked key returns null
    expect($apiKeyService->validate($plainKey))->toBeNull();
});

test('accounting generic CSV exports: sales journal, general ledger, and inventory valuation', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-INT-3', tenantName: 'Integration Test Shop 3', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $cat = Category::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'ACC-CAT', 'name' => 'Accounting Category']);
    $taxRate = TaxRate::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'GST10', 'name' => 'GST 10%', 'rate' => '10.0000']);
    $taxGroup = TaxGroup::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'STD', 'name' => 'Standard', 'mode' => 'exclusive']);
    TaxGroupItem::create(['tax_group_id' => $taxGroup->id, 'tax_rate_id' => $taxRate->id]);

    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-ACC-01', 'name' => 'Accounting Export Item',
        'category_id' => $cat->id, 'base_unit_id' => $unit->id, 'is_active' => true, 'tax_group_id' => $taxGroup->id,
    ]);
    $priceList = PriceList::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true,
    ]);
    ProductPrice::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_key' => 0,
        'price_list_id' => $priceList->id, 'unit_id' => $unit->id, 'price' => '100.0000',
    ]);

    app(StockService::class)->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'in', 'quantity' => '25.0000', 'unit_cost' => '40.0000', 'source_type' => 'opening_stock',
    ]);

    $customer = Customer::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'CUST-ACC-1', 'name' => 'Accounting Customer']);
    app(CustomerLedgerService::class)->recordEntry($tenant->id, $customer->id, 'debit', '500.0000', 'opening_balance', null, null, $owner->id);

    $cash = PaymentMethod::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'confirmation_mode' => 'instant', 'allows_overpayment' => true,
    ]);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');
    $auth = ['Authorization' => 'Bearer '.$login['access_token']];

    $shift = app(ShiftService::class)->open($tenant->id, $register->id, '100.0000', $owner->id);

    // Make a sale
    $saleResp = test()->withHeaders($auth)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/sales', [
        'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'register_id' => $register->id,
        'price_list_id' => $priceList->id, 'cashier_shift_id' => $shift->id, 'customer_id' => $customer->id,
        'lines' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => '2.0000']],
        'payments' => [['payment_method_id' => $cash->id, 'amount' => '220.0000']],
    ])->assertStatus(201)->json('data');

    $from = now()->subDay()->toDateTimeString();
    $to = now()->addDay()->toDateTimeString();

    // 1. Export Sales Journal CSV
    $salesCsvResp = test()->withHeaders($auth)->get('/api/v1/integrations/accounting/export-sales?'.http_build_query(['from' => $from, 'to' => $to]))
        ->assertStatus(200);

    expect($salesCsvResp->headers->get('content-type'))->toContain('text/csv');
    $salesCsv = $salesCsvResp->getContent();
    expect($salesCsv)->toContain('Invoice_Number,Date,Branch,Customer,Subtotal,Discount,Tax,Grand_Total,Status');
    expect($salesCsv)->toContain($saleResp['invoice']);
    expect($salesCsv)->toContain('Accounting Customer');

    // 2. Export General Ledger CSV
    $ledgerCsvResp = test()->withHeaders($auth)->get('/api/v1/integrations/accounting/export-ledger?'.http_build_query(['from' => $from, 'to' => $to]))
        ->assertStatus(200);

    expect($ledgerCsvResp->headers->get('content-type'))->toContain('text/csv');
    $ledgerCsv = $ledgerCsvResp->getContent();
    expect($ledgerCsv)->toContain('Date,Ledger_Type,Party_Code,Party_Name,Source_Type,Direction,Amount,Balance_After,Description');
    expect($ledgerCsv)->toContain('CUST-ACC-1');
    expect($ledgerCsv)->toContain('500.0000');

    // 3. Export Inventory Valuation CSV
    $invCsvResp = test()->withHeaders($auth)->get('/api/v1/integrations/accounting/export-inventory')
        ->assertStatus(200);

    expect($invCsvResp->headers->get('content-type'))->toContain('text/csv');
    $invCsv = $invCsvResp->getContent();
    expect($invCsv)->toContain('Warehouse,SKU,Product_Name,Category,Quantity,Average_Cost,Valuation');
    expect($invCsv)->toContain('SKU-ACC-01');
    expect($invCsv)->toContain('Accounting Category');
});
