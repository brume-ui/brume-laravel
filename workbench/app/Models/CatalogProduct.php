<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Workbench demo model behind 商品.
 */
class CatalogProduct extends Model
{
    protected $table = 'catalog_products';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'gallery' => 'array',
            'is_published' => 'boolean',
            'is_preorder' => 'boolean',
            'is_bundle' => 'boolean',
            'preorder_ships_at' => 'datetime',
            'erp_updated_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'catalog_product_components', 'bundle_id', 'component_id')
            ->withPivot(['quantity', 'position'])
            ->orderByPivot('position');
    }
}
