<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * section 7 "price lists + history": `price_list_items` is the append-style
 * history (a price change closes the previous row's effective_to and
 * inserts a new one - PriceListService, not raw CRUD); `product_prices` is
 * the fast "current price" lookup cache kept in sync by that same service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('price_list_id')->constrained('price_lists');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('price', 18, 4);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'price_list_id', 'product_id', 'variant_id', 'effective_from'], 'pli_lookup_idx');
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants');
            // MySQL/MariaDB/SQLite treat NULL as distinct in a unique index,
            // so `variant_id` alone can't enforce "one current price per
            // product+list+unit" for variant-less products (both would have
            // variant_id = NULL). variant_key mirrors variant_id but is 0
            // instead of NULL, and carries the real uniqueness constraint -
            // same pattern as stock_balances below (docs/DECISIONS.md D-0010).
            $table->unsignedBigInteger('variant_key')->default(0);
            $table->foreignId('price_list_id')->constrained('price_lists');
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('price', 18, 4);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'product_id', 'variant_key', 'price_list_id', 'unit_id'], 'product_prices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
