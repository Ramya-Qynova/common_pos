<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rule engine tables (section 6 "Rule engine"). Each row's `condition` is
 * { all: [{field, op, value}], any: [...] } and `action` a fixed-shape JSON
 * payload - the engine (Phase 2+) only ever evaluates this closed grammar,
 * never arbitrary code (docs/threat-model.md, "Elevation of privilege via
 * SQL/NoSQL injection").
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['discount_rules', 'approval_rules', 'alert_rules', 'loyalty_rules', 'price_rules'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->string('code');
                $table->string('name');
                $table->json('condition');
                $table->json('action');
                $table->unsignedInteger('priority')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('row_version')->default(1);
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'code']);
            });
        }
    }

    public function down(): void
    {
        foreach (['price_rules', 'loyalty_rules', 'alert_rules', 'approval_rules', 'discount_rules'] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
