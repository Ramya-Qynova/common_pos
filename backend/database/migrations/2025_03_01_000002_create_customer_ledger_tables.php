<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 7 "Customers": customer_ledger, customer_payments,
 * customer_payment_allocations. Balances are derived, never typed in
 * (section 8 "Ledgers and collections") - append-only (G5, G9), direction
 * debit (customer owes more - opening balance, a future sale) or credit
 * (customer owes less - a payment, a future return). Allocation targets
 * the ledger's own debit rows directly rather than a separate "invoices"
 * table, since sales invoices don't exist until Phase 4 - a debit row IS
 * the thing owed, whatever created it (see docs/DECISIONS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('direction'); // debit|credit
            $table->decimal('amount', 18, 4);
            $table->decimal('allocated_amount', 18, 4)->default(0); // for debit rows: how much of this charge has been paid off
            $table->string('source_type'); // opening_balance|manual_adjustment|payment|sale|return (sale/return wired in Phase 4/5)
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('balance_after', 18, 4);
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('customers');
            $table->decimal('amount', 18, 4);
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods');
            $table->string('reference')->nullable();
            $table->foreignId('received_by')->constrained('users');
            $table->timestamp('received_at');
            $table->timestamps();
        });

        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained('customer_payments');
            $table->foreignId('customer_ledger_id')->constrained('customer_ledger'); // the debit row being paid off
            $table->decimal('amount', 18, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_allocations');
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('customer_ledger');
    }
};
