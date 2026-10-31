<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Composition extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'compositions';

    protected function casts(): array
    {
        return ['delivery' => 'boolean', 'notes' => 'array'];
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(CompositionNode::class)->whereNull('parent_id')->orderBy('position');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(CompositionCatalog::class, 'owner_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(CompositionCatalog::class);
    }

    public function featured(): BelongsToMany
    {
        return $this->belongsToMany(CompositionCatalog::class, 'composition_featured', 'composition_id', 'catalog_id')->withPivot('quantity');
    }
}
