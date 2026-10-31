<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompositionNode extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'composition_nodes';

    protected $attributes = ['gift_type' => 'product'];

    protected function casts(): array
    {
        return ['notes' => 'array', 'amount' => 'decimal:2', 'coupon_amount' => 'decimal:2', 'coupon_expires_at' => 'datetime', 'points' => 'integer'];
    }

    public function thresholds(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function gifts(): HasMany
    {
        return $this->hasMany(CompositionGift::class, 'node_id');
    }
}
