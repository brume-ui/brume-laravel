<?php

namespace Brume\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rows that belong to a shop, with one column per inline editor, for the cell update tests
 * (tests/database/migrations).
 *
 * @property int $shop_id
 * @property string $customer
 * @property string $status open|paid|done
 * @property int $amount
 * @property list<string>|null $tags
 * @property bool $is_active
 * @property bool $is_featured
 * @property bool $is_new
 * @property string|null $note
 * @property string|null $password
 */
class CellItem extends Model
{
    protected $guarded = [];

    protected $hidden = ['password'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'price' => 'decimal:2',
            'tags' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
        ];
    }
}
