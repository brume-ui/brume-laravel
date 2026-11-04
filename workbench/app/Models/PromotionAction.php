<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromotionAction extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'promotion_actions';

    protected $guarded = [];

    public function condition(): BelongsTo
    {
        return $this->belongsTo(PromotionCondition::class, 'condition_id');
    }

    public function freebies(): HasMany
    {
        return $this->hasMany(PromotionFreebie::class, 'action_id')->orderBy('position');
    }
}
