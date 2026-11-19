<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/** Workbench demo model behind 首頁橫幅 (a paged list ordered by dragging). */
class HomeBanner extends Model
{
    protected $table = 'home_banners';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}
