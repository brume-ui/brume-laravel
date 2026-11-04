<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Workbench demo model behind 商品分類 (an arbitrary-depth tree).
 *
 * @property string $tree_path sortable materialized path, maintained on save
 * @property-read int $tree_depth 0 for a root, +1 per ancestor
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
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(CatalogProduct::class, 'category_id');
    }

    protected static function booted(): void
    {
        // A new category goes after its siblings, like the original sortable tree.
        static::creating(function (self $category): void {
            $category->position ??= (int) self::query()->where('parent_id', $category->parent_id)->max('position') + 1;
        });
        static::saved(fn (self $category) => $category->refreshTreePath());
    }

    /** Ordering by the tree path lists every category right under its parent (siblings by position, id). */
    public function scopeTreeOrder(Builder $query): void
    {
        $query->orderBy($query->qualifyColumn('tree_path'));
    }

    /** Stores this category's path and, when it moved, its descendants' paths too. */
    public function refreshTreePath(array $seen = []): void
    {
        if (in_array($this->getKey(), $seen, true)) {
            return;
        }
        $parent = $this->parent_id === null ? '' : (string) self::query()->whereKey($this->parent_id)->value('tree_path');
        $path = $parent.sprintf('%06d.%010d/', $this->position, $this->getKey());
        if ($path === $this->tree_path) {
            return;
        }
        $this->forceFill(['tree_path' => $path])->saveQuietly();
        foreach (self::query()->where('parent_id', $this->getKey())->get() as $child) {
            $child->refreshTreePath([...$seen, $this->getKey()]);
        }
    }

    /** 0 for a root, +1 per ancestor, read from the stored path (no query per row). */
    protected function treeDepth(): Attribute
    {
        return Attribute::get(fn (): int => max(0, substr_count((string) $this->tree_path, '/') - 1));
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
