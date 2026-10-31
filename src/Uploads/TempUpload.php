<?php

namespace Brume\Uploads;

use Illuminate\Database\Eloquent\Model;

class TempUpload extends Model
{
    protected $table = 'brume_uploads';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['row_context' => 'array', 'expires_at' => 'datetime', 'created_at' => 'datetime', 'size' => 'integer'];
    }
}
