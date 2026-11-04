<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Workbench demo model behind 商品分類 (an arbitrary-depth tree).
 *
 * @property-read int $tree_depth 0 for a root, +1 per real ancestor
 */
class ProductCategory extends Model
{
    public $timestamps = false;

    protected $table = 'product_categories';

    protected $guarded = [];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function products(): HasMany
    {
        return $this->hasMany(CatalogProduct::class, 'category_id');
    }

    /** Walks the real ancestor chain, so it stays correct at any depth (and survives a cycle). */
    protected function treeDepth(): Attribute
    {
        return Attribute::get(function (): int {
            $depth = 0;
            $seen = [$this->getKey()];
            $parentId = $this->parent_id;

            while ($parentId !== null && ! in_array($parentId, $seen, true)) {
                $seen[] = $parentId;
                $depth++;
                $parentId = self::query()->whereKey($parentId)->value('parent_id');
            }

            return $depth;
        });
    }

    /** @return list<int> every descendant id, at any depth */
    public function descendantIds(): array
    {
        $ids = [];
        $frontier = [$this->getKey()];

        while ($frontier !== []) {
            $frontier = self::query()->whereIn('parent_id', $frontier)->whereNotIn('id', [...$ids, $this->getKey()])
                ->orderBy('id')->pluck('id')->all();
            array_push($ids, ...$frontier);
        }

        return $ids;
    }
}
