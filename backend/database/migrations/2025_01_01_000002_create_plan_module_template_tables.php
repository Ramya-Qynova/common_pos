<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level catalogue data (docs/MASTER_PROMPT.md sections 2, 6): plans,
 * module definitions, business-type templates, languages, feature flags.
 * None of this is tenant-owned; it is what a tenant is built FROM at
 * onboarding (section 8 "Onboarding" - the template is copied, not referenced
 * live, into tenant_template_snapshot in the next migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('module_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('dependencies')->nullable();
            $table->json('permissions')->nullable();
            $table->json('menu')->nullable();
            $table->boolean('is_core')->default(false);
            $table->timestamps();
        });

        Schema::create('plan_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('module_code');
            $table->timestamps();
            $table->unique(['plan_id', 'module_code']);
        });

        Schema::create('plan_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('key');
            $table->json('value');
            $table->timestamps();
            $table->unique(['plan_id', 'key']);
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('templates');
            $table->unsignedInteger('version');
            $table->json('data');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['template_id', 'version']);
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->boolean('default_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('template_versions');
        Schema::dropIfExists('templates');
        Schema::dropIfExists('plan_limits');
        Schema::dropIfExists('plan_modules');
        Schema::dropIfExists('module_definitions');
        Schema::dropIfExists('plans');
    }
};
