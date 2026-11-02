<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Register;
use App\Models\Sale;
use App\Models\TaxGroup;
use App\Models\TaxGroupItem;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Finance\ShiftService;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

test('advanced reports: profit and loss, tax breakdown, inventory valuation, and cash reconciliation', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-ADV-1', tenantName: 'Advanced Report Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);

    $category = Category::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'ELECTRONICS', 'name' => 'Electronics',
    ]);

    $taxRate1 = TaxRate::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'CGST9', 'name' => 'CGST 9%', 'rate' => '9.0000']);
    $taxRate2 = TaxRate::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'SGST9', 'name' => 'SGST 9%', 'rate' => '9.0000']);
    $taxGroup = TaxGroup::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'GST18', 'name' => 'GST 18%', 'mode' => 'exclusive']);
    TaxGroupItem::create(['tax_group_id' => $taxGroup->id, 'tax_rate_id' => $taxRate1->id]);
    TaxGroupItem::create(['tax_group_id' => $taxGroup->id, 'tax_rate_id' => $taxRate2->id]);

    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-ADV-1', 'name' => 'Advanced Product',
        'category_id' => $category->id, 'base_unit_id' => $unit->id, 'is_active' => true, 'tax_group_id' => $taxGroup->id,
    ]);

    $priceList = PriceList::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true,
    ]);
    ProductPrice::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_key' => 0,
        'price_list_id' => $priceList->id, 'unit_id' => $unit->id, 'price' => '100.0000',
    ]);

    // Initial stock: 100 units @ 50.0000 cost
    app(StockService::class)->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'in', 'quantity' => '100.0000', 'unit_cost' => '50.0000', 'source_type' => 'opening_stock',
    ]);

    $cash = PaymentMethod::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'confirmation_mode' => 'instant', 'allows_overpayment' => true,
    ]);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');
    $auth = ['Authorization' => 'Bearer '.$login['access_token']];

    // Shift 1: open with 200.0000 cash
    $shift = app(ShiftService::class)->open($tenant->id, $register->id, '200.0000', $owner->id);

    // Make a sale: 4 units @ 100.0000 = 400.0000 gross taxable
    // CGST 9% = 36.0000, SGST 9% = 36.0000, Grand total = 472.0000
    // COGS = 4 * 50.0000 = 200.0000
    $saleResp = test()->withHeaders($auth)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/sales', [
        'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'register_id' => $register->id,
        'price_list_id' => $priceList->id, 'cashier_shift_id' => $shift->id,
        'lines' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => '4.0000']],
        'payments' => [['payment_method_id' => $cash->id, 'amount' => '472.0000']],
    ])->assertStatus(201);

    $sale = Sale::withoutTenantScope()->with('items')->find($saleResp->json('data.id'));
    $saleItem = $sale->items->first();

    // Return 1 unit resellable:
    // Returned taxable = 100.0000
    // Returned CGST = 9.0000, SGST = 9.0000
    // Refund amount = 118.0000
    // Returned COGS = 50.0000
    $retResp = test()->withHeaders($auth)->postJson("/api/v1/sales/{$sale->id}/returns", [
        'items' => [['sale_item_id' => $saleItem->id, 'quantity' => '1.0000', 'condition' => 'resellable']],
    ])->assertStatus(201);
    test()->withHeaders($auth)->postJson("/api/v1/returns/{$retResp->json('data.id')}/inspect")->assertStatus(200);
    test()->withHeaders($auth)->postJson("/api/v1/returns/{$retResp->json('data.id')}/complete", ['refund_method' => 'cash'])->assertStatus(201);

    // Record an operating expense: 30.0000
    $expenseCat = ExpenseCategory::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'UTILITIES', 'name' => 'Utilities',
    ]);
    Expense::withoutTenantScope()->create([
        'tenant_id' => $tenant->id,
        'uuid' => (string) Str::uuid(),
        'expense_category_id' => $expenseCat->id,
        'amount' => '30.0000',
        'description' => 'Electricity bill',
        'cashier_shift_id' => $shift->id,
        'paid_by' => $owner->id,
        'paid_at' => now(),
    ]);

    // Close the shift:
    // Expected cash = 200 (open) + 472 (sale) - 118 (refund) = 554.0000
    // Counted cash = 550.0000 -> difference = -4.0000 (shortage)
    test()->withHeaders($auth)->postJson("/api/v1/shifts/{$shift->id}/close", [
        'counted_cash' => '550.0000',
    ])->assertStatus(200);

    $from = now()->subDay()->toDateTimeString();
    $to = now()->addDay()->toDateTimeString();

    // 1. Test Profit & Loss
    $plResp = test()->withHeaders($auth)->getJson('/api/v1/reports/profit-loss?'.http_build_query(['from' => $from, 'to' => $to]))
        ->assertStatus(200)
        ->json('data');

    // Gross Sales = 4 * 100.0000 = 400.0000
    expect(bccomp($plResp['gross_sales'], '400.0000', 4))->toBe(0);
    // Returns taxable = 100.0000
    expect(bccomp($plResp['returns'], '100.0000', 4))->toBe(0);
    // Net sales = 400 - 100 = 300.0000
    expect(bccomp($plResp['net_sales'], '300.0000', 4))->toBe(0);
    // COGS = 200 - 50 = 150.0000
    expect(bccomp($plResp['cogs'], '150.0000', 4))->toBe(0);
    // Gross profit = 300 - 150 = 150.0000
    expect(bccomp($plResp['gross_profit'], '150.0000', 4))->toBe(0);
    // Operating expenses = 30.0000
    expect(bccomp($plResp['operating_expenses'], '30.0000', 4))->toBe(0);
    // Net profit = 150 - 30 = 120.0000
    expect(bccomp($plResp['net_profit'], '120.0000', 4))->toBe(0);

    // 2. Test Tax Breakdown
    $taxResp = test()->withHeaders($auth)->getJson('/api/v1/reports/tax-breakdown?'.http_build_query(['from' => $from, 'to' => $to]))
        ->assertStatus(200)
        ->json('data');

    expect($taxResp['components'])->toHaveCount(2);
    // CGST: collected 36.0000, refunded 9.0000, net 27.0000
    $cgst = collect($taxResp['components'])->firstWhere('tax_code', 'CGST9');
    expect($cgst)->not->toBeNull();
    expect(bccomp($cgst['collected_amount'], '36.0000', 4))->toBe(0);
    expect(bccomp($cgst['refunded_amount'], '9.0000', 4))->toBe(0);
    expect(bccomp($cgst['net_amount'], '27.0000', 4))->toBe(0);

    // SGST: collected 36.0000, refunded 9.0000, net 27.0000
    $sgst = collect($taxResp['components'])->firstWhere('tax_code', 'SGST9');
    expect($sgst)->not->toBeNull();
    expect(bccomp($sgst['collected_amount'], '36.0000', 4))->toBe(0);
    expect(bccomp($sgst['refunded_amount'], '9.0000', 4))->toBe(0);
    expect(bccomp($sgst['net_amount'], '27.0000', 4))->toBe(0);

    // Total net tax = 54.0000
    expect(bccomp($taxResp['totals']['net_amount'], '54.0000', 4))->toBe(0);

    // 3. Test Inventory Valuation
    $invResp = test()->withHeaders($auth)->getJson('/api/v1/reports/inventory-valuation')
        ->assertStatus(200)
        ->json('data');

    // Remaining stock: 100 - 4 + 1 = 97.0000 units @ 50.0000 = 4850.0000
    expect(bccomp($invResp['summary']['total_quantity'], '97.0000', 4))->toBe(0);
    expect(bccomp($invResp['summary']['total_valuation'], '4850.0000', 4))->toBe(0);
    expect($invResp['summary']['total_skus'])->toBe(1);
    expect($invResp['by_warehouse'])->toHaveCount(1);
    expect($invResp['by_category'])->toHaveCount(1);
    expect($invResp['by_category'][0]['category_name'])->toBe('Electronics');

    // 4. Test Cash Reconciliation
    $cashResp = test()->withHeaders($auth)->getJson('/api/v1/reports/cash-reconciliation?'.http_build_query(['from' => $from, 'to' => $to]))
        ->assertStatus(200)
        ->json('data');

    expect($cashResp['shifts'])->toHaveCount(1);
    $shiftReport = $cashResp['shifts'][0];
    expect(bccomp($shiftReport['opening_cash'], '200.0000', 4))->toBe(0);
    expect(bccomp($shiftReport['cash_sales'], '472.0000', 4))->toBe(0);
    expect(bccomp($shiftReport['cash_refunds'], '118.0000', 4))->toBe(0);
    expect(bccomp($shiftReport['expected_cash'], '554.0000', 4))->toBe(0);
    expect(bccomp($shiftReport['counted_cash'], '550.0000', 4))->toBe(0);
    expect(bccomp($shiftReport['cash_difference'], '-4.0000', 4))->toBe(0);
    expect($shiftReport['register_name'])->not->toBeEmpty();
});
