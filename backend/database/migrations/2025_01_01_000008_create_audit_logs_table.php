<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail (G4, G5, G9, G15). Every critical write's audit
 * row shares the same DB transaction as the write it documents - enforced
 * in the service layer, not here. No UPDATE/DELETE path is exposed to the
 * application for this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->foreignId('platform_user_id')->nullable()->constrained('platform_users');
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('correlation_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
