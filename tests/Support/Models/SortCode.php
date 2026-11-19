<?php

namespace Brume\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rows keyed by a string code with a nullable sort column, for the reorder tests
 * (tests/database/migrations).
 *
 * @property string $code
 * @property string $name
 * @property int|null $sort
 */
class SortCode extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public $timestamps = false;
}
