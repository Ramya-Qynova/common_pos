<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('code');
            $table->string('name');
            $table->string('type'); // receipt|invoice|credit_note
            $table->json('layout');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('hardware_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->foreignId('register_id')->nullable()->constrained('registers');
            $table->string('code');
            $table->string('type'); // printer|scanner|scale
            $table->json('config');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        // Offline operation policy (section 9). device_id null = the
        // tenant-wide default for that operation_type; a device-specific row
        // overrides it. Uniqueness ("at most one tenant-default row per
        // operation_type") is enforced by the seeder/service, not a DB
        // constraint - see docs/data-dictionary.md and docs/DECISIONS.md.
        Schema::create('offline_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('device_id')->nullable()->constrained('devices');
            $table->string('operation_type');
            $table->boolean('is_allowed')->default(false);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();
            $table->index(['tenant_id', 'device_id', 'operation_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_policies');
        Schema::dropIfExists('hardware_profiles');
        Schema::dropIfExists('document_templates');
    }
};
