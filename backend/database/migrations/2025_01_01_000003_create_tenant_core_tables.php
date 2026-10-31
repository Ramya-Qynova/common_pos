<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('shop_code')->unique();
            $table->string('name');
            $table->string('status')->default('draft'); // draft|setup|active|suspended|closed - section 8
            $table->foreignId('plan_id')->nullable()->constrained('plans');
            $table->foreignId('template_id')->nullable()->constrained('templates');
            $table->string('timezone')->default('Asia/Kolkata');
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('status')->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('module_code');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'module_code']);
        });

        // Copy-on-onboard snapshot of the template (section 8 "Onboarding"):
        // later template updates never silently change a live tenant.
        Schema::create('tenant_template_snapshot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants');
            $table->foreignId('template_id')->constrained('templates');
            $table->unsignedInteger('template_version');
            $table->json('data');
            $table->timestamp('applied_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_template_snapshot');
        Schema::dropIfExists('tenant_modules');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('tenants');
    }
};
