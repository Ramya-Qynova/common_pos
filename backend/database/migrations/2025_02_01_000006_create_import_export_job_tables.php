<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 8 "Master data": "Bulk import is a background job: validate each
 * row, save valid rows, return a downloadable error report. Nothing
 * silently skipped." `errors` holds that report inline as JSON for now
 * (row number + message per rejected row) rather than a separate
 * downloadable file - see docs/DECISIONS.md for why that's an acceptable
 * Phase 2 scope boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->string('type'); // products|...
            $table->string('status')->default('pending'); // pending|processing|completed|failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->json('errors')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->string('file_path')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('import_jobs');
    }
};
