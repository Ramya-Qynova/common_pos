<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookup_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('code');
            $table->string('name');
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('lookup_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lookup_type_id')->constrained('lookup_types');
            $table->string('code');
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['lookup_type_id', 'code']);
        });

        // Per-entity custom field schema (section 6 "Custom fields"). Values
        // live in each entity's own `custom_fields` JSON column (added when
        // that entity's table is created in a later phase); custom_field_index
        // is the searchable side-index for fields marked `searchable`.
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('entity_type');
            $table->string('code');
            $table->string('label');
            $table->string('type'); // text|number|decimal|date|boolean|select|multi_select|file
            $table->boolean('required')->default(false);
            $table->json('default_value')->nullable();
            $table->json('validation')->nullable();
            $table->json('visible_when')->nullable();
            $table->boolean('searchable')->default(false);
            $table->boolean('show_in_list')->default(false);
            $table->boolean('show_on_receipt')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'entity_type', 'code']);
        });

        Schema::create('custom_field_index', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('field_code');
            $table->string('value_text')->nullable();
            $table->decimal('value_number', 18, 4)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'entity_type', 'field_code', 'value_text'], 'cfi_tenant_entity_field_value_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_index');
        Schema::dropIfExists('custom_field_definitions');
        Schema::dropIfExists('lookup_values');
        Schema::dropIfExists('lookup_types');
    }
};
