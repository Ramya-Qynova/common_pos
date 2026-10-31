<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 8 "Purchasing": optional PO -> receiving is one transaction
 * (validate, create purchase, lock, ledger rows at received cost, update PO
 * status, compute payable -> supplier_ledger debit). This closes the loop
 * D-0016/D-0022 flagged: supplier_ledger debits now have a real source
 * (`purchase`), not just manual/opening entries. Purchase returns reverse
 * stock at original cost and create a supplier credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->string('status')->default('draft'); // draft|ordered|partially_received|received|cancelled
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('ordered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('received_quantity', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders');
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->decimal('subtotal', 18, 4);
            $table->decimal('tax_total', 18, 4);
            $table->decimal('grand_total', 18, 4);
            $table->foreignId('received_by')->constrained('users');
            $table->timestamp('received_at');
            $table->timestamps();
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4);
            $table->decimal('returned_quantity', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('purchase_id')->constrained('purchases');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('completed_at');
            $table->timestamps();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns');
            $table->foreignId('purchase_item_id')->constrained('purchase_items');
            $table->decimal('quantity', 18, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
