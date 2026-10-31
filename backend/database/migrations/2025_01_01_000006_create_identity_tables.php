<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-side identity/RBAC (section 5 "Identity", G11). `users` here is the
 * tenant guard's user table - structurally unrelated to platform_users.
 * Roles are tenant-owned rows (seeded from the template snapshot at
 * onboarding, section 8) so a tenant-created custom role behaves exactly
 * like a seeded "standard" one (section 13.1 "Permissions").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('module_code')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('code');
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles');
            $table->string('permission_code');
            $table->timestamps();
            $table->unique(['role_id', 'permission_code']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->string('username');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->unsignedInteger('failed_login_count')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'username']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('role_id')->constrained('roles');
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('user_branch_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('branch_id')->constrained('branches');
            $table->timestamps();
            $table->unique(['user_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_branch_access');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
