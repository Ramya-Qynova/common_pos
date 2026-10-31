<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 8 "Stock operations": opening stock/adjustment/damage/wastage
 * need `inventory.adjust` plus a mandatory reason, and approval if a rule
 * matches. Approval-rule evaluation itself is schema-only until the rule
 * engine gets a real evaluator (see docs/reports/phase-2.md); this phase
 * enforces the permission + mandatory reason part and records who posted
 * it, which is independently real and tested.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->string('type'); // opening_stock|adjustment|damage|wastage
            $table->string('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('posted_at');
            $table->timestamps();
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('batch_id')->nullable()->constrained('batches');
            $table->string('direction'); // in|out
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->nullable(); // required for direction=in
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
