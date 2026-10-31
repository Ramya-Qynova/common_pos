<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split into platform defaults (global, one row per language+key) and
 * tenant overrides, rather than one table with a nullable tenant_id -
 * MySQL/MariaDB treat NULL as distinct in a unique index, so a nullable
 * tenant_id could not actually enforce "one platform default per key"
 * (see docs/DECISIONS.md for this and the similar offline_policies case).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_translations', function (Blueprint $table) {
            $table->id();
            $table->string('language_code', 10);
            $table->string('key');
            $table->text('value');
            $table->boolean('needs_review')->default(false);
            $table->timestamps();
            $table->unique(['language_code', 'key']);
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('language_code', 10);
            $table->string('key');
            $table->text('value');
            $table->boolean('needs_review')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'language_code', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
        Schema::dropIfExists('platform_translations');
    }
};
