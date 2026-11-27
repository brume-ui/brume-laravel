<?php

use Mews\Captcha\CaptchaServiceProvider;

require __DIR__.'/../vendor/autoload.php';

// mews/captcha 3.5 casts with `(double)`, a compile-time deprecation on PHP 8.5 that would fail the
// suite (failOnDeprecation). Compile its provider here, before PHPUnit installs its error handler.
if (class_exists(CaptchaServiceProvider::class, false) === false) {
    @class_exists(CaptchaServiceProvider::class);
}
