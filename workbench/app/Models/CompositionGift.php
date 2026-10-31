<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompositionGift extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    use SoftDeletes;

    protected $table = 'composition_gifts';
}
