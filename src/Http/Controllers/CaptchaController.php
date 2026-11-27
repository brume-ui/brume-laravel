<?php

namespace Brume\Http\Controllers;

use Brume\Form\Fields\Captcha;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * The captcha image, served inside the admin's prefix and middleware so it shares the session the
 * form is submitted with. The renderer adds `?r=<n>` to reload it; the value is ignored.
 */
class CaptchaController
{
    /** Test seam: the cache check is skipped under unit tests unless a test turns this on. */
    public static bool $checkCacheWhileTesting = false;

    public function show(Request $request, string $config = 'default'): Response
    {
        abort_unless(Captcha::installed() && ! config('captcha.disable') && self::isImageConfig(config('captcha.'.$config)), 404);

        $this->warnWhenCacheCannotPersist();

        $response = app('captcha')->create($config);
        // mews only remembers the last generated key, not the config it came from: record both so a
        // form can insist on the config it declared (see Captcha::assertAnswer()).
        session()->put(Captcha::SESSION_KEY, ['config' => $config, 'key' => session('captcha.key')]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * mews/captcha keeps the expected answer in the default cache store between this image and the
     * submit. `array` and `null` forget it after the request, so every answer would be refused: say
     * so (the image is still served).
     */
    private function warnWhenCacheCannotPersist(): void
    {
        if (app()->runningUnitTests() && ! self::$checkCacheWhileTesting) {
            return;
        }
        $store = (string) config('cache.default');
        $driver = config('cache.stores.'.$store.'.driver');
        if (in_array($driver, ['array', 'null'], true)) {
            report(new RuntimeException('The captcha answer is kept in the cache, but the default cache store "'.$store.'" ('.$driver.' driver) does not keep anything between requests: every answer will be refused. Use a persistent store such as file, database or redis.'));
        }
    }

    /** config/captcha.php also holds `characters` and the directories; only a size block draws an image. */
    private static function isImageConfig(mixed $config): bool
    {
        return is_array($config) && (isset($config['width']) || isset($config['height']));
    }
}
