<?php

namespace Brume\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tree rows keyed by a string code (parent_code / sort), for the tree view tests
 * (tests/database/migrations).
 *
 * @property string $code
 * @property string $name
 * @property string|null $parent_code
 * @property int|null $sort
 */
class TreeCode extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public $timestamps = false;
}
