<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Workbench demo model behind 網站選單 (a two-level tree ordered by dragging). */
class NavMenu extends Model
{
    public $timestamps = false;

    protected $table = 'nav_menus';

    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order')->orderBy('id');
    }
}
