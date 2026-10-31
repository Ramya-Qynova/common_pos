<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 7 "sales": sale_items stores cost_snapshot at sale time (returns
 * and profit never use current cost) and price_source. section 8 "POS
 * sale": server computes everything via PricingEngine - these tables only
 * ever receive server-computed values (G1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid');
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('register_id')->constrained('registers');
            $table->foreignId('cashier_shift_id')->nullable()->constrained('cashier_shifts');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->string('status')->default('completed'); // completed|cancelled
            $table->string('payment_status')->default('paid'); // pending|partial|paid|failed|partially_refunded|refunded
            $table->decimal('line_subtotal', 18, 4);
            $table->decimal('discount_total', 18, 4);
            $table->decimal('taxable_amount', 18, 4);
            $table->decimal('tax_total', 18, 4);
            $table->decimal('grand_total', 18, 4);
            $table->boolean('is_test')->default(false);
            $table->string('origin')->default('online'); // online|offline
            $table->foreignId('device_id')->nullable()->constrained('devices');
            $table->timestamp('client_created_at')->nullable();
            $table->timestamp('server_created_at');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['tenant_id', 'uuid']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('batch_id')->nullable()->constrained('batches');
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('line_discount_amount', 18, 4);
            $table->decimal('taxable_amount', 18, 4);
            $table->decimal('tax_amount', 18, 4);
            $table->decimal('line_total', 18, 4);
            $table->decimal('cost_snapshot', 18, 4);
            $table->string('price_source')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_item_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->constrained('sale_items');
            $table->string('tax_code');
            $table->decimal('amount', 18, 4);
            $table->timestamps();
        });

        Schema::create('sale_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales');
            $table->string('type'); // line|invoice
            $table->foreignId('sale_item_id')->nullable()->constrained('sale_items');
            $table->unsignedBigInteger('discount_rule_id')->nullable();
            $table->decimal('amount', 18, 4);
            $table->string('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_discounts');
        Schema::dropIfExists('sale_item_taxes');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
