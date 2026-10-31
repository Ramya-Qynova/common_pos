<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 7 "invoices": unique (tenant, series, fiscal_year, number),
 * generated under a locked sequence row (numbering_sequences, Phase 1).
 * section 10 "Payment confirmation modes": instant|manual_confirm|
 * provider_confirm - payment_transactions tracks the provider-facing
 * status independent of the payment record itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('sale_id')->unique()->constrained('sales');
            $table->string('series');
            $table->string('fiscal_year')->nullable();
            $table->unsignedBigInteger('number');
            $table->string('prefix')->nullable();
            $table->string('full_number');
            $table->string('status')->default('final'); // final|cancelled
            $table->timestamps();
            $table->unique(['tenant_id', 'series', 'fiscal_year', 'number']);
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales');
            $table->foreignId('payment_method_id')->constrained('payment_methods');
            $table->decimal('amount', 18, 4);
            $table->decimal('change_given', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('sale_payment_id')->nullable()->constrained('sale_payments');
            $table->string('provider_reference')->nullable();
            $table->string('status')->default('succeeded'); // pending|succeeded|failed|reversed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('invoices');
    }
};
