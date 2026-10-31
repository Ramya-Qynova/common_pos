<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 8 "End of day": finance daily close with approval (day becomes
 * closed; later corrections are dated today via reversing entries;
 * touching a closed day needs finance.reopen). One row per branch per
 * business date (tenant-scoped unique, section "Tenant-scoped uniqueness").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->date('business_date');
            $table->string('status')->default('open'); // open|closed|reopened
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users');
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_closings');
    }
};
