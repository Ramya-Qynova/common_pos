<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 9 "Offline-first design": "Outcomes go to sync_operations and
 * conflicts to sync_conflicts." `sync_operations.uuid` is the client's own
 * `operation_uuid` - the dedupe key that makes resubmitting the same
 * operation return the original result instead of reprocessing (section
 * 13.2 "resubmit same operation UUID -> earlier result, nothing new").
 * `change_log` is what `GET /sync/pull?since=` reads for an incremental
 * diff - deliberately scoped to the entities the IndexedDB snapshot
 * stores actually need (products, prices), not retrofitted onto every
 * mutable table this build has (see docs/DECISIONS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid'); // the client's operation_uuid
            $table->foreignId('device_id')->nullable()->constrained('devices');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('type');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->foreignId('register_id')->nullable()->constrained('registers');
            $table->timestamp('client_created_at')->nullable();
            $table->timestamp('received_at');
            $table->string('status'); // accepted|rejected|held
            $table->string('reject_reason')->nullable();
            $table->json('result_payload')->nullable();
            $table->string('hash')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'uuid']);
        });

        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('sync_operation_id')->constrained('sync_operations');
            $table->string('type'); // STOCK_SHORTFALL|PRICE_MISMATCH|DATA_CHANGED|CLOCK_SKEW
            $table->json('details')->nullable();
            $table->string('status')->default('open'); // open|resolved
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('change_log', function (Blueprint $table) {
            $table->id(); // doubles as the monotonic "version" GET /sync/pull?since= cursors on
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('action'); // created|updated|archived
            $table->json('data');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_log');
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_operations');
    }
};
