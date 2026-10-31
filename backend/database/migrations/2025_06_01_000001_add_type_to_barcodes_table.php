<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 9 adapter interfaces: "BarcodeScanner (keyboard-wedge default)".
 * A `fixed` barcode is looked up by its exact scanned value (unchanged
 * behaviour). A `variable_weight` barcode stores only its FIXED prefix
 * (prefix + product code, per the tenant-configurable `pos.
 * variable_weight_barcode` format) - the weight/price suffix differs every
 * time the same product is weighed, so it is decoded, never stored.
 * New migration (not an edit to the Phase 2 file - rule 1.12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barcodes', function (Blueprint $table) {
            $table->string('type')->default('fixed')->after('barcode'); // fixed|variable_weight
        });
    }

    public function down(): void
    {
        Schema::table('barcodes', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
