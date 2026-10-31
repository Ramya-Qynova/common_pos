<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G6: "Every mutating POS and sync request carries an idempotency key or
 * operation UUID, unique on (tenant_id, key), with a request hash. Same
 * key + same payload replays the original response... Same key +
 * different payload returns IDEMPOTENCY_KEY_REUSED."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('key');
            $table->string('request_hash', 64);
            $table->string('status')->default('in_progress'); // in_progress|completed
            $table->json('response_body')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
