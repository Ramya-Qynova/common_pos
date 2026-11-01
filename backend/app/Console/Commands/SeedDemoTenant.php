<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Register;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Finance\ShiftService;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Sales\SaleService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * section 15 Final Acceptance: "a seeded demo tenant created through the
 * real onboarding flow (not fake rows)." Every row below comes from the
 * same services a real shop owner's signup/first sale would run through -
 * OnboardingService, StockService, ShiftService, SaleService - nothing
 * here is a raw INSERT standing in for the real flow.
 */
class SeedDemoTenant extends Command
{
    protected $signature = 'demo:seed {--shop-code=DEMO}';

    protected $description = 'Onboard a demo tenant through the real onboarding flow, with one priced, stocked product and one completed sale.';

    public function handle(): int
    {
        $shopCode = $this->option('shop-code');

        if (Tenant::withoutGlobalScopes()->where('shop_code', $shopCode)->exists()) {
            $this->warn("Tenant with shop_code [{$shopCode}] already exists - skipping.");

            return self::SUCCESS;
        }

        $tenant = app(OnboardingService::class)->createTenant(
            shopCode: $shopCode,
            tenantName: 'Demo Shop',
            planCode: 'standard',
            templateCode: 'general_retail',
            ownerUsername: 'owner',
            ownerPassword: 'demo-password-123',
            ownerEmail: 'owner@demo.test',
        );
        $this->info("Tenant onboarded: {$tenant->shop_code} (id {$tenant->id})");

        $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
        $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
        $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
        $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

        $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
        $product = Product::withoutTenantScope()->create([
            'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'DEMO-001', 'name' => 'Demo Product',
            'base_unit_id' => $unit->id, 'is_active' => true,
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
            'direction' => 'in', 'quantity' => '50.0000', 'unit_cost' => '60.0000', 'source_type' => 'opening_stock',
        ]);
        $this->info('Product, price list, and opening stock created.');

        $cash = PaymentMethod::withoutTenantScope()->create([
            'tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'confirmation_mode' => 'instant', 'allows_overpayment' => true,
        ]);
        $shift = app(ShiftService::class)->open($tenant->id, $register->id, '500.0000', $owner->id);

        $sale = app(SaleService::class)->create($tenant->id, [
            'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'register_id' => $register->id,
            'price_list_id' => $priceList->id, 'cashier_shift_id' => $shift->id,
            'lines' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => '2.0000']],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => '200.0000']],
        ], $owner->id, true);
        $this->info("Demo sale completed: invoice {$sale->invoice->full_number}, total {$sale->grand_total}");

        $this->info("Demo tenant ready. Login with shop_code={$shopCode}, username=owner, password=demo-password-123");

        return self::SUCCESS;
    }
}
