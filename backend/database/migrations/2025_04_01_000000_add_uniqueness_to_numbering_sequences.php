<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `numbering_sequences` (Phase 1, 2025_01_01_000009) was created with no
 * uniqueness constraint at all. NumberingService::next() (Phase 4) relies
 * on "lock the row, or insert-then-lock-on-conflict if it doesn't exist
 * yet" to make first-time sequence creation race-safe - without a real
 * constraint, two concurrent sales in a brand-new series could each
 * insert their own row and both hand out invoice number 1. Added here
 * rather than editing the Phase 1 migration (rule 1.12).
 *
 * `fiscal_year`/`device_id` are nullable, so (same reasoning as D-0013/
 * D-0018) bucket columns carry the real constraint instead of the
 * nullable originals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('numbering_sequences', function (Blueprint $table) {
            $table->string('fiscal_year_key')->default('');
            $table->unsignedBigInteger('device_key')->default(0);
        });

        DB::table('numbering_sequences')->update([
            'fiscal_year_key' => DB::raw("COALESCE(fiscal_year, '')"),
            'device_key' => DB::raw('COALESCE(device_id, 0)'),
        ]);

        Schema::table('numbering_sequences', function (Blueprint $table) {
            $table->unique(['tenant_id', 'series', 'fiscal_year_key', 'device_key'], 'numbering_sequences_unique');
        });
    }

    public function down(): void
    {
        Schema::table('numbering_sequences', function (Blueprint $table) {
            $table->dropUnique('numbering_sequences_unique');
            $table->dropColumn(['fiscal_year_key', 'device_key']);
        });
    }
};
