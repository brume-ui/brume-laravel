<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $given_count gifts handed out under the current promotion
 * @property int $already_given_count gifts handed out before this promotion (historical)
 */
class PromotionFreebie extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'promotion_freebies';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_limited' => 'boolean'];
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(PromotionAction::class, 'action_id');
    }
}
