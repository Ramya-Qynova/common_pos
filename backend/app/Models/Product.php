<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read Collection<int,Barcode> $barcodes
 */
class Product extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'uuid', 'sku', 'name', 'category_id', 'brand_id', 'base_unit_id',
        'tax_group_id', 'hsn_code', 'track_batches', 'track_serials', 'custom_fields', 'is_active',
    ];

    protected function casts(): array
    {
        return ['track_batches' => 'boolean', 'track_serials' => 'boolean', 'custom_fields' => 'array', 'is_active' => 'boolean'];
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(Barcode::class);
    }
}
