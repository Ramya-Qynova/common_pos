<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->string('sku');
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->foreignId('brand_id')->nullable()->constrained('brands');
            $table->foreignId('base_unit_id')->constrained('units');
            $table->foreignId('tax_group_id')->nullable()->constrained('tax_groups');
            $table->string('hsn_code')->nullable();
            $table->boolean('track_batches')->default(false);
            $table->boolean('track_serials')->default(false);
            $table->json('custom_fields')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
        });

        // units a product may be bought/sold in, each with a conversion
        // factor back to the product's base_unit_id (section 6).
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('conversion_factor', 18, 6); // 1 of this unit = N base units
            $table->boolean('is_purchase_default')->default(false);
            $table->boolean('is_sale_default')->default(false);
            $table->timestamps();
            $table->unique(['product_id', 'unit_id']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('product_id')->constrained('products');
            $table->uuid('uuid')->unique();
            $table->string('sku');
            $table->string('name');
            $table->json('attributes')->nullable(); // e.g. {"size":"L","color":"Red"}
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
        });

        Schema::create('barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('unit_id')->nullable()->constrained('units');
            $table->string('barcode');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'barcode']);
        });

        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('product_id')->constrained('products'); // the composite/kit product
            $table->foreignId('component_product_id')->constrained('products');
            $table->foreignId('component_variant_id')->nullable()->constrained('product_variants');
            $table->decimal('quantity', 18, 4);
            $table->foreignId('unit_id')->constrained('units');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_recipes');
        Schema::dropIfExists('barcodes');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('products');
    }
};
