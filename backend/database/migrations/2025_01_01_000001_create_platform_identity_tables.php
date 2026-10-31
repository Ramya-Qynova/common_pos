<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform guard (docs/MASTER_PROMPT.md section 5 "Identity"): platform
 * users are structurally separate from tenant users - a different table,
 * a different guard, no tenant_id column, and (by construction - there is
 * no FK path from these tables into any tenant-owned table) no route by
 * which a platform user's queries can reach tenant business data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_roles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('platform_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_role_id')->constrained('platform_roles');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_user_id')->constrained('platform_users');
            $table->string('access_token_hash', 64)->unique();
            $table->string('refresh_token_hash', 64)->unique();
            $table->timestamp('access_expires_at');
            $table->timestamp('refresh_expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('rotated_from_id')->nullable()->references('id')->on('platform_sessions');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_sessions');
        Schema::dropIfExists('platform_users');
        Schema::dropIfExists('platform_roles');
    }
};
