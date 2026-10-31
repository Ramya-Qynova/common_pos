<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 7: "Held bills live in held_sales, never in sales." Resuming a
 * held sale re-runs it through the normal SaleService::create() pricing
 * path (G1) - held_sale_items stores only what the cashier picked, not a
 * frozen price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('register_id')->constrained('registers');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('created_by')->constrained('users');
            $table->string('status')->default('held'); // held|resumed|expired|deleted
            $table->timestamp('held_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('held_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('held_sale_id')->constrained('held_sales');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('quantity', 18, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_sale_items');
        Schema::dropIfExists('held_sales');
    }
};
