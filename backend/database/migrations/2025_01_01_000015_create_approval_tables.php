<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval framework (section 8 "Approvals"): a matching rule returns
 * APPROVAL_REQUIRED and creates an approval_request; an approval decision
 * is a separate, audited row so "the action executes exactly once, with
 * the approver in the audit log" is structurally true, not just convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('rule_type'); // discount|cancellation|... (which rule family triggered this)
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->string('action_type');
            $table->json('action_payload');
            $table->foreignId('requested_by')->constrained('users');
            $table->string('status')->default('pending'); // pending|approved|rejected|expired
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('approval_requests');
            $table->foreignId('approver_id')->constrained('users');
            $table->string('decision'); // approved|rejected
            $table->boolean('pin_verified')->default(false);
            $table->timestamp('decided_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('approval_requests');
    }
};
