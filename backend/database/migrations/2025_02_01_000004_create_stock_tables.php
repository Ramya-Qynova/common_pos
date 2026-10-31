<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 7 "Critical table rules": stock_balances unique on
 * (tenant, warehouse, product, variant?, batch?), upsert on first
 * movement, rows locked FOR UPDATE in a consistent order
 * (warehouse, product, variant, batch) - implemented in StockService, not
 * here. stock_ledger is append-only with a mandatory unit_cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('product_id')->constrained('products');
            $table->string('batch_number');
            $table->date('expiry_date')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'product_id', 'batch_number']);
        });

        Schema::create('serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses');
            $table->string('serial_number');
            $table->string('status')->default('in_stock'); // in_stock|sold|damaged|returned
            $table->timestamps();
            $table->unique(['tenant_id', 'serial_number']);
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('batch_id')->nullable()->constrained('batches');
            // See product_prices above for why *_key columns exist: NULL is
            // not unique-constrainable across DB engines, so variant_key/
            // batch_key (0 = "none") carry the real uniqueness constraint
            // that keeps "upsert on first movement" from ever creating two
            // balance rows for the same variant-less/batch-less product.
            $table->unsignedBigInteger('variant_key')->default(0);
            $table->unsignedBigInteger('batch_key')->default(0);
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('average_cost', 18, 4)->default(0);
            $table->decimal('reorder_point', 18, 4)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'warehouse_id', 'product_id', 'variant_key', 'batch_key'], 'stock_balances_unique');
        });

        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('batch_id')->nullable()->constrained('batches');
            $table->string('direction'); // in|out
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->string('source_type'); // opening_stock|adjustment|purchase|sale|return|transfer
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->decimal('balance_after', 18, 4);
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'warehouse_id', 'product_id', 'variant_id', 'batch_id'], 'stock_ledger_lookup_idx');
        });

        // FIFO strategy support (section 1.2: "FIFO via cost_layers is
        // implemented if time allows, otherwise left as a clean strategy
        // interface"). Schema only this phase - weighted average is the
        // Release 1 default and the only costing method with real service
        // logic right now (see docs/DECISIONS.md).
        Schema::create('cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('batch_id')->nullable()->constrained('batches');
            $table->decimal('quantity_remaining', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->timestamp('received_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_layers');
        Schema::dropIfExists('stock_ledger');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('serial_numbers');
        Schema::dropIfExists('batches');
    }
};
