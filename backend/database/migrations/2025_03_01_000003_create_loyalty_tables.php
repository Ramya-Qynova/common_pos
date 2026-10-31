<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('customer_id')->constrained('customers');
            $table->decimal('points_balance', 18, 4)->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'customer_id']);
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('loyalty_account_id')->constrained('loyalty_accounts');
            $table->string('direction'); // earn|redeem|adjust
            $table->decimal('points', 18, 4);
            $table->string('source_type'); // manual|sale (sale wired in Phase 4)
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('balance_after', 18, 4);
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_accounts');
    }
};
