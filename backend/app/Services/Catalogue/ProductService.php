<?php

namespace App\Services\Catalogue;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    public function create(int $tenantId, array $data, ?int $userId = null): Product
    {
        if (Product::withoutTenantScope()->where('tenant_id', $tenantId)->where('sku', $data['sku'])->exists()) {
            throw new ApiException('VALIDATION_FAILED', "SKU [{$data['sku']}] already exists.", 422, ['sku' => 'already in use']);
        }

        return DB::transaction(function () use ($tenantId, $data, $userId) {
            $product = Product::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'uuid' => (string) Str::uuid(),
                'sku' => $data['sku'],
                'name' => $data['name'],
                'category_id' => $data['category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'base_unit_id' => $data['base_unit_id'],
                'tax_group_id' => $data['tax_group_id'] ?? null,
                'hsn_code' => $data['hsn_code'] ?? null,
                'track_batches' => $data['track_batches'] ?? false,
                'track_serials' => $data['track_serials'] ?? false,
                'custom_fields' => $data['custom_fields'] ?? null,
                'is_active' => true,
            ]);

            AuditLogger::log($tenantId, $userId, 'product.created', 'Product', $product->id, null, $product->toArray());

            return $product;
        });
    }

    /**
     * G9: products with transactions are archived, never deleted.
     */
    public function archive(Product $product, ?int $userId = null): Product
    {
        $before = $product->toArray();
        $product->archived_at = now();
        $product->is_active = false;
        $product->save();

        AuditLogger::log($product->tenant_id, $userId, 'product.archived', 'Product', $product->id, $before, $product->toArray());

        return $product;
    }
}
