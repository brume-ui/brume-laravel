<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionCondition extends Model
{
    public $timestamps = false;

    protected $table = 'promotion_conditions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function actions(): HasMany
    {
        return $this->hasMany(PromotionAction::class, 'condition_id')->orderBy('position');
    }
}
