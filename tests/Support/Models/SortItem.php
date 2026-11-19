<?php

namespace Brume\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rows of a shop with a nullable sort column, for the reorder tests (tests/database/migrations).
 *
 * @property int $shop_id
 * @property string $name
 * @property int|null $sort
 */
class SortItem extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }
}
