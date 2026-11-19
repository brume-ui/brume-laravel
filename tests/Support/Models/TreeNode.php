<?php

namespace Brume\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rows of a shop in a parent_id / order tree, for the tree view tests (tests/database/migrations).
 *
 * @property int $shop_id
 * @property string $name
 * @property int|null $parent_id
 * @property int|null $order
 */
class TreeNode extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'order' => 'integer'];
    }
}
