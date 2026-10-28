<?php

namespace Brume\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rows keyed by a string code, for the string-key lookup tests (tests/database/migrations).
 *
 * @property string $code
 * @property string $name
 * @property string|float|null $price
 */
class CodeItem extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public $timestamps = false;
}
