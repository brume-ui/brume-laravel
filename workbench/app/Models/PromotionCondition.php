<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A threshold. Soft-deleted like its actions and gifts, so a removed threshold keeps its trashed
 * gift rows (and their counters) attached instead of a cascading hard delete.
 */
class PromotionCondition extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'promotion_conditions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(PromotionAction::class, 'condition_id')->orderBy('position');
    }
}
