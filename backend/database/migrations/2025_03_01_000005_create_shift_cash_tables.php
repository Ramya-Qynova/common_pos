<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 8 "Shifts and cash": "Expected cash = opening cash + cash
 * received (net of change) - cash refunds - cash expenses + cash in -
 * cash out." Each term is a cash_movements row of the matching type, so
 * the formula is a SUM over typed rows rather than separate columns -
 * sales/refunds don't exist until Phase 4/5, so those two terms are
 * always zero until then, but the formula and the shift mechanics
 * (one-open-shift-per-register, immutability on close) are fully real now.
 *
 * `open_register_marker` is a classic nullable-unique trick: NULL (closed
 * shifts) never collides under any engine's NULL-is-distinct rule, but a
 * non-NULL value (this shift's register_id, set only while status='open')
 * is uniquely constrained - so "one open shift per register" is a real DB
 * constraint, not just an application-level check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('register_id')->constrained('registers');
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->decimal('opening_cash', 18, 4);
            $table->decimal('closing_cash_counted', 18, 4)->nullable();
            $table->decimal('expected_cash', 18, 4)->nullable();
            $table->decimal('cash_difference', 18, 4)->nullable();
            $table->string('status')->default('open'); // open|closed
            $table->unsignedBigInteger('open_register_marker')->nullable()->unique();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('cashier_shift_id')->constrained('cashier_shifts');
            $table->string('type'); // opening|cash_in|cash_out|expense|cash_sale|cash_refund
            $table->decimal('amount', 18, 4);
            $table->string('reason')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'cashier_shift_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cashier_shifts');
    }
};
