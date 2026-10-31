<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Org model (section 5 "Org model"): tenant -> branches -> warehouses
 * (each branch has a default sales warehouse) -> registers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->string('code');
            $table->string('name');
            $table->string('timezone')->default('Asia/Kolkata');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('branches');
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('branch_id')->constrained('branches');
            $table->uuid('uuid')->unique();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_default_sales')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->uuid('uuid')->unique();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registers');
        Schema::dropIfExists('warehouses');
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
        });
        Schema::dropIfExists('branches');
    }
};
