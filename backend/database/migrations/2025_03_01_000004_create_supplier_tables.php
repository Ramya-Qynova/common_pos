<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 7 "Purchases": suppliers, supplier_ledger, supplier_payments,
 * supplier_payment_allocations - master data and the ledger/payment
 * mechanism only. Purchase orders/receiving (the normal source of a
 * supplier debit) are Phase 5; this phase's debits come from opening
 * balances / manual adjustments, same pattern as customer_ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->string('code');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('supplier_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->string('direction'); // debit (we owe more) | credit (we owe less)
            $table->decimal('amount', 18, 4);
            $table->decimal('allocated_amount', 18, 4)->default(0);
            $table->string('source_type'); // opening_balance|manual_adjustment|payment|purchase|purchase_return
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('balance_after', 18, 4);
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'supplier_id']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->decimal('amount', 18, 4);
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods');
            $table->string('reference')->nullable();
            $table->foreignId('paid_by')->constrained('users');
            $table->timestamp('paid_at');
            $table->timestamps();
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained('supplier_payments');
            $table->foreignId('supplier_ledger_id')->constrained('supplier_ledger');
            $table->decimal('amount', 18, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_ledger');
        Schema::dropIfExists('suppliers');
    }
};
