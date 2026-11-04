<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** The bundles this product is a component of. */
    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'catalog_product_components', 'component_id', 'bundle_id');
    }

    /** Live gift rows (their action, threshold and promotion still exist) that hand out this product. */
    public function liveGifts(): HasMany
    {
        return $this->hasMany(PromotionFreebie::class, 'product_id')->whereHas('action.condition.promotion');
    }
}
