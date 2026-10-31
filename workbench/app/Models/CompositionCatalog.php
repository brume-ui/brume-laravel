<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

class CompositionCatalog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'composition_catalog';
}
