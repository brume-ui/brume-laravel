<?php

namespace Brume\Http\Controllers;

use Brume\Form\Fields\Captcha;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The captcha image, served inside the admin's prefix and middleware so it shares the session the
 * form is submitted with. The renderer adds `?r=<n>` to reload it; the value is ignored.
 */
class CaptchaController
{
    public function show(Request $request, string $config = 'default'): Response
    {
        abort_unless(Captcha::installed() && is_array(config('captcha.'.$config)), 404);

        $response = app('captcha')->create($config);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
