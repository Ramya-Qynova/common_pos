<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three-layer settings catalogue backing SettingsService (section 4
 * "Settings resolution"): platform_settings is the typed catalogue + global
 * default for every key; plan_settings overrides per plan; tenant_settings
 * overrides per tenant and, with branch_id set, per branch. Resolution order
 * is branch -> tenant -> plan -> platform, implemented in the service, not
 * here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->string('type'); // string|integer|decimal|boolean|enum|json - validated by SettingsService
            $table->json('allowed_values')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('plan_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('key');
            $table->json('value');
            $table->timestamps();
            $table->unique(['plan_id', 'key']);
        });

        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('branch_id')->nullable(); // FK added once branches exists (next migration)
            $table->string('key');
            $table->json('value');
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('plan_settings');
        Schema::dropIfExists('platform_settings');
    }
};
