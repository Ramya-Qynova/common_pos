<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Rollback note: Drops stock_transfer_items, stock_transfers,
     * stock_reconciliation_items, stock_reconciliations in reverse order.
     */
    public function up(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_reconciliation_items');
        Schema::dropIfExists('stock_reconciliations');

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('transfer_number', 64);
            $table->foreignId('source_warehouse_id')->constrained('warehouses');
            $table->foreignId('destination_warehouse_id')->constrained('warehouses');
            $table->string('status', 32)->default('draft'); // draft, dispatched, received, partially_received, cancelled
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('dispatched_by')->nullable()->constrained('users');
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'transfer_number']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('batch_id')->nullable()->constrained('batches');
            $table->decimal('quantity_dispatched', 18, 4);
            $table->decimal('quantity_received', 18, 4)->default('0.0000');
            $table->decimal('discrepancy_quantity', 18, 4)->default('0.0000');
            $table->decimal('unit_cost', 18, 4)->default('0.0000');
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('stock_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('reference_number', 64);
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->string('status', 32)->default('in_progress'); // draft, in_progress, completed, cancelled
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'reference_number']);
        });

        Schema::create('stock_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_reconciliation_id')->constrained('stock_reconciliations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('batch_id')->nullable()->constrained('batches');
            $table->decimal('system_quantity', 18, 4);
            $table->decimal('counted_quantity', 18, 4)->nullable();
            $table->decimal('difference_quantity', 18, 4)->default('0.0000');
            $table->decimal('unit_cost', 18, 4)->default('0.0000');
            $table->decimal('variance_value', 18, 4)->default('0.0000');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reconciliation_items');
        Schema::dropIfExists('stock_reconciliations');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
