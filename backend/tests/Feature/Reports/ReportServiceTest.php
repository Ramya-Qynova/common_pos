<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\TaxGroup;
use App\Models\TaxGroupItem;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Customers\CustomerLedgerService;
use App\Services\Finance\ShiftService;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Purchasing\SupplierLedgerService;
use Illuminate\Support\Str;

/**
 * section 8 "Reports (defined once)": every figure here is checked against
 * numbers computed BY HAND before the test was written, not against
 * whatever the code happens to produce (the exit gate's own wording:
 * "Reports match hand-computed fixtures").
 *
 * Fixture: opening stock 50 units @ cost 40.0000. One sale of 3 units @
 * 100.0000 with a 10% exclusive tax group -> line taxable 300.0000, tax
 * 30.0000, line_total 330.0000, cost 120.0000 (3*40). One resellable
 * return of 1 of those 3 units, refunded in cash (the sale's own method):
 *   returned taxable = 1/3 * 300.0000      = 100.0000
 *   returned tax      = 1/3 * 30.0000       =  10.0000
 *   returned cogs      = 1 * 40.0000         =  40.0000
 *   refund amount      = 1/3 * 330.0000      = 110.0000
 *
 * Hand-computed expectations:
 *   gross sales      = 3 * 100.0000                     = 300.0000  (returns never reduce gross)
 *   net sales        = 300.0000 - 0 (discount) - 100.0000 (returned taxable) = 200.0000
 *   tax collected    = 30.0000 - 10.0000                = 20.0000
 *   COGS             = 120.0000 - 40.0000                = 80.0000
 *   gross profit     = 200.0000 - 80.0000                = 120.0000
 *   stock valuation  = (50 - 3 + 1) * 40.0000 = 48 * 40.0000 = 1920.0000
 *   expected cash    = 100.0000 (opening) + 330.0000 (cash sale) - 110.0000 (cash refund) = 320.0000
 */
test('every report figure matches a hand-computed fixture', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-RPT-1', tenantName: 'Report Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);

    $taxRate = TaxRate::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'GST10', 'name' => 'GST 10%', 'rate' => '10.0000']);
    $taxGroup = TaxGroup::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'STD', 'name' => 'Standard', 'mode' => 'exclusive']);
    TaxGroupItem::create(['tax_group_id' => $taxGroup->id, 'tax_rate_id' => $taxRate->id]);

    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-RPT', 'name' => 'Report Product',
        'base_unit_id' => $unit->id, 'is_active' => true, 'tax_group_id' => $taxGroup->id,
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
        'direction' => 'in', 'quantity' => '50.0000', 'unit_cost' => '40.0000', 'source_type' => 'opening_stock',
    ]);

    $cash = PaymentMethod::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'confirmation_mode' => 'instant', 'allows_overpayment' => true,
    ]);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');
    $auth = ['Authorization' => 'Bearer '.$login['access_token']];

    $shift = app(ShiftService::class)->open($tenant->id, $register->id, '100.0000', $owner->id);

    $saleResponse = test()->withHeaders($auth)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/sales', [
        'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'register_id' => $register->id,
        'price_list_id' => $priceList->id, 'cashier_shift_id' => $shift->id,
        'lines' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => '3.0000']],
        'payments' => [['payment_method_id' => $cash->id, 'amount' => '330.0000']],
    ])->assertStatus(201);

    expect(bccomp($saleResponse->json('data.grand_total'), '330.0000', 4))->toBe(0); // sanity-check the fixture itself

    $sale = Sale::withoutTenantScope()->with('items')->find($saleResponse->json('data.id'));
    $saleItem = $sale->items->first();

    $return = test()->withHeaders($auth)->postJson("/api/v1/sales/{$sale->id}/returns", [
        'items' => [['sale_item_id' => $saleItem->id, 'quantity' => '1.0000', 'condition' => 'resellable']],
    ])->assertStatus(201);
    test()->withHeaders($auth)->postJson("/api/v1/returns/{$return->json('data.id')}/inspect")->assertStatus(200);
    $refund = test()->withHeaders($auth)->postJson("/api/v1/returns/{$return->json('data.id')}/complete", ['refund_method' => 'cash'])->assertStatus(201);
    expect(bccomp($refund->json('data.amount'), '110.0000', 4))->toBe(0); // sanity-check the fixture itself

    $from = now()->subDay()->toDateTimeString();
    $to = now()->addDay()->toDateTimeString();

    $report = fn (string $name, array $extra = []) => test()->withHeaders($auth)
        ->getJson('/api/v1/reports?'.http_build_query(array_merge(['report' => $name, 'from' => $from, 'to' => $to], $extra)))
        ->json('data.value');

    expect(bccomp($report('gross_sales'), '300.0000', 4))->toBe(0);
    expect(bccomp($report('net_sales'), '200.0000', 4))->toBe(0);
    expect(bccomp($report('tax_collected'), '20.0000', 4))->toBe(0);
    expect(bccomp($report('cogs'), '80.0000', 4))->toBe(0);
    expect(bccomp($report('gross_profit'), '120.0000', 4))->toBe(0);
    expect(bccomp($report('stock_valuation'), '1920.0000', 4))->toBe(0);
    expect(bccomp($report('expected_cash', ['shift_id' => $shift->id]), '320.0000', 4))->toBe(0);
});

test('outstanding sums each customer/supplier\'s CURRENT (latest) ledger balance, not a running total of every entry', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-RPT-2', tenantName: 'Report Test Shop 2', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $customer = Customer::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'CUST-RPT', 'name' => 'Report Customer']);
    app(CustomerLedgerService::class)->recordEntry($tenant->id, $customer->id, 'debit', '500.0000', 'opening_balance', null, null, $owner->id);
    app(CustomerLedgerService::class)->collectPayment($tenant->id, $customer->id, '200.0000', null, null, $owner->id);

    $supplier = Supplier::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'SUP-RPT', 'name' => 'Report Supplier']);
    app(SupplierLedgerService::class)->recordEntry($tenant->id, $supplier->id, 'debit', '800.0000', 'opening_balance', null, null, $owner->id);
    app(SupplierLedgerService::class)->makePayment($tenant->id, $supplier->id, '300.0000', null, null, $owner->id);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])->getJson('/api/v1/reports?'.http_build_query(['report' => 'outstanding', 'from' => now()->toDateString(), 'to' => now()->toDateString()]));

    expect(bccomp($response->json('data.value.customer'), '300.0000', 4))->toBe(0);
    expect(bccomp($response->json('data.value.supplier'), '500.0000', 4))->toBe(0);
});
