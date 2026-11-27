<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/** Workbench demo model behind 驗證碼示範 (a one-field form guarded by a captcha). */
class CaptchaDemo extends Model
{
    protected $table = 'captcha_demos';

    protected $guarded = [];
}
