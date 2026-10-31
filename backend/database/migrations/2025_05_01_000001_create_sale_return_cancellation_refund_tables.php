<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 8 "Cancel / Return / Refund / Exchange": cancel reverses a whole
 * sale within sales.cancel_window; return validates quantity <= sold minus
 * already returned (state machine requested/inspected/completed/rejected,
 * skip inspection if returns.require_inspection is false); refund amount
 * always derives from the ORIGINAL sale_item values, never current prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('sale_id')->unique()->constrained('sales');
            $table->string('reason')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at');
            $table->timestamps();
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('sale_id')->constrained('sales');
            $table->string('status')->default('requested'); // requested|inspected|completed|rejected
            $table->string('reason')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('inspected_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained('sale_returns');
            $table->foreignId('sale_item_id')->constrained('sale_items');
            $table->decimal('quantity', 18, 4);
            $table->string('condition')->default('resellable'); // resellable|damaged
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('sale_id')->constrained('sales');
            $table->foreignId('sale_return_id')->nullable()->constrained('sale_returns');
            $table->foreignId('sale_cancellation_id')->nullable()->constrained('sale_cancellations');
            $table->decimal('amount', 18, 4);
            $table->string('method'); // cash|credit|original
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods');
            $table->string('status')->default('pending'); // pending|completed|failed
            $table->foreignId('processed_by')->constrained('users');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('sale_cancellations');
    }
};
