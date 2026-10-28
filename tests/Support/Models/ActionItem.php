<?php

namespace Brume\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Soft-deletable rows that belong to a shop, for the action tests (tests/database/migrations).
 *
 * @property int $shop_id
 * @property string $name
 * @property string $status open|locked|done
 */
class ActionItem extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public $timestamps = false;
}
