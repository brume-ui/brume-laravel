<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Workbench demo model behind 滿額贈.
 */
class Promotion extends Model
{
    protected $table = 'promotions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_enabled' => 'boolean'];
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(PromotionCondition::class)->orderBy('position');
    }
}
