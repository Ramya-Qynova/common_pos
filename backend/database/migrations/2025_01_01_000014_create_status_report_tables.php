<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customizable label/colour per status code (section 4). Seeded per
        // tenant at onboarding from the template snapshot, same pattern as
        // roles - avoids the nullable-tenant_id uniqueness problem entirely.
        Schema::create('status_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('entity_type');
            $table->string('status_code');
            $table->string('label');
            $table->string('color')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'entity_type', 'status_code']);
        });

        Schema::create('report_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants');
            $table->string('code');
            $table->string('name');
            $table->json('parameters')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');
        Schema::dropIfExists('status_definitions');
    }
};
