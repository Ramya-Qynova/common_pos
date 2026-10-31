<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Invoice/offline numbering sequences (section 7 "invoices", section 9
        // "Offline numbering"). device_id null = the tenant's main online
        // series; set = a device's own device_series counter.
        Schema::create('numbering_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('series');
            $table->foreignId('device_id')->nullable()->constrained('devices');
            $table->string('fiscal_year')->nullable();
            $table->string('prefix')->nullable();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('code');
            $table->string('name');
            $table->decimal('rate', 9, 4);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('tax_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('code');
            $table->string('name');
            $table->string('mode'); // inclusive|exclusive
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('tax_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_group_id')->constrained('tax_groups');
            $table->foreignId('tax_rate_id')->constrained('tax_rates');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('code');
            $table->string('name');
            $table->string('confirmation_mode'); // instant|manual_confirm|provider_confirm
            $table->boolean('allows_overpayment')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('tax_group_items');
        Schema::dropIfExists('tax_groups');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('numbering_sequences');
    }
};
