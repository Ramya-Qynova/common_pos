<?php

use App\Models\Barcode;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Register;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalogue\VariableWeightBarcodeService;
use App\Services\Finance\ShiftService;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\TemplateUpdateService;
use Illuminate\Support\Str;

/**
 * section 9 "Adapter interfaces": BarcodeScanner. The scanned code below is
 * a fabricated EAN-13-style variable-weight barcode matching the format
 * seeded in `pos.variable_weight_barcode` (prefix "2", 5-digit product
 * code at position 1, 5-digit weight at position 6, 3 implied decimals):
 *   "2" . "00012" . "00456" . "00" = "2000120045600" (13 digits)
 * decodes to product-code-prefix "200012" and weight 456/1000 = 0.4560 kg.
 * The trailing "00" is unused by decode() (no check-digit validation this
 * phase - see docs/DECISIONS.md).
 */
function setUpWeighingFixture(string $shopCode, string $templateCode): array
{
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: $shopCode, tenantName: 'Weighing Test Shop', planCode: 'standard',
        templateCode: $templateCode, ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    app(TemplateUpdateService::class)->apply($tenant->id, $owner->id);

    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $kg = Unit::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'kg')->first();

    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-WEIGH', 'name' => 'Loose Tomatoes',
        'base_unit_id' => $kg->id, 'is_active' => true,
    ]);

    Barcode::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'product_id' => $product->id, 'unit_id' => $kg->id,
        'barcode' => '200012', 'type' => 'variable_weight', 'is_primary' => true,
    ]);

    $priceList = PriceList::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true,
    ]);
    ProductPrice::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_key' => 0,
        'price_list_id' => $priceList->id, 'unit_id' => $kg->id, 'price' => '80.0000', // per kg
    ]);

    app(StockService::class)->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'in', 'quantity' => '50.0000', 'unit_cost' => '40.0000', 'source_type' => 'opening_stock',
    ]);

    $cash = PaymentMethod::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'confirmation_mode' => 'instant', 'allows_overpayment' => true,
    ]);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');
    $shift = app(ShiftService::class)->open($tenant->id, $register->id, '0.0000', $owner->id);

    return compact('tenant', 'branch', 'warehouse', 'register', 'kg', 'product', 'priceList', 'cash', 'login', 'shift');
}

test('a variable-weight barcode decodes to the right product and quantity on a grocery (weighing-enabled) tenant', function () {
    $fixture = setUpWeighingFixture('SHOP-WEIGH-1', 'grocery');

    $resolved = app(VariableWeightBarcodeService::class)->resolve($fixture['tenant']->id, '2000120045600');

    expect($resolved)->not->toBeNull();
    expect($resolved['product_id'])->toBe($fixture['product']->id);
    expect($resolved['unit_id'])->toBe($fixture['kg']->id);
    expect(bccomp($resolved['quantity'], '0.4560', 4))->toBe(0);
    expect($resolved['barcode_type'])->toBe('variable_weight');
});

test('the same scanned code resolves to nothing on a general_retail tenant - the weighing module gates it, not a template check', function () {
    $fixture = setUpWeighingFixture('SHOP-WEIGH-2', 'general_retail');

    // Same barcode row exists (fixture creates it regardless of template), but 'weighing' isn't
    // enabled for this tenant, so the variable-weight decode path is never attempted.
    $resolved = app(VariableWeightBarcodeService::class)->resolve($fixture['tenant']->id, '2000120045600');

    expect($resolved)->toBeNull();
});

test('a fixed barcode still resolves by exact match for any tenant, weighing or not', function () {
    $fixture = setUpWeighingFixture('SHOP-WEIGH-3', 'general_retail');
    Barcode::withoutTenantScope()->create([
        'tenant_id' => $fixture['tenant']->id, 'product_id' => $fixture['product']->id, 'unit_id' => $fixture['kg']->id,
        'barcode' => 'FIXED-001', 'type' => 'fixed', 'is_primary' => false,
    ]);

    $resolved = app(VariableWeightBarcodeService::class)->resolve($fixture['tenant']->id, 'FIXED-001');

    expect($resolved['product_id'])->toBe($fixture['product']->id);
    expect($resolved['quantity'])->toBeNull();
    expect($resolved['barcode_type'])->toBe('fixed');
});

test('a weighed quantity resolved from a barcode feeds straight into a normal sale - no special grocery code path, just a fractional quantity (G3)', function () {
    $fixture = setUpWeighingFixture('SHOP-WEIGH-4', 'grocery');
    $resolved = app(VariableWeightBarcodeService::class)->resolve($fixture['tenant']->id, '2000120045600');

    $response = test()->withHeader('Authorization', 'Bearer '.$fixture['login']['access_token'])
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/sales', [
            'branch_id' => $fixture['branch']->id, 'warehouse_id' => $fixture['warehouse']->id, 'register_id' => $fixture['register']->id,
            'price_list_id' => $fixture['priceList']->id, 'cashier_shift_id' => $fixture['shift']->id,
            'lines' => [['product_id' => $resolved['product_id'], 'unit_id' => $resolved['unit_id'], 'quantity' => $resolved['quantity']]],
            'payments' => [['payment_method_id' => $fixture['cash']->id, 'amount' => bcmul($resolved['quantity'], '80.0000', 4)]],
        ]);

    $response->assertStatus(201);
    // 0.4560 kg * 80.0000/kg = 36.4800, then cash-rounded to the nearest
    // locale.cash_rounding_step (platform default "1.00", half_up) -> 36.0000.
    // This rounding is PricingEngine's own pre-existing behaviour (Phase 4),
    // not anything grocery-specific - the point here is that a fractional,
    // barcode-derived quantity reaches the ordinary sale path at all.
    expect(bccomp($response->json('data.grand_total'), '36.0000', 4))->toBe(0);
});
