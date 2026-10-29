<?php

namespace Brume\Support;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * URLs the page follows or links to: only http, https, mailto, tel and relative URLs, never
 * javascript: or data:.
 */
final class Href
{
    /**
     * The href, or null for a scheme other than http, https, mailto and tel, such as javascript: or
     * data:. Relative URLs pass. Browsers ignore whitespace and control characters around and
     * inside a scheme, so they are dropped before it is read.
     */
    public static function safe(mixed $href): ?string
    {
        if ($href === null) {
            return null;
        }

        $href = (string) $href;
        $compact = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $href));

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $compact, $match) === 1
            && ! in_array($match[1], ['http', 'https', 'mailto', 'tel'], true)) {
            return null;
        }

        return $href;
    }

    /**
     * A developer-supplied URL (editUrl(), Feedback::redirect(), Feedback::download()) checked by
     * safe(): a refused one throws in the local environment, so it is fixed while developing, and
     * elsewhere is dropped (null) with a warning in the log instead of reaching the page.
     */
    public static function checked(?string $href, string $where): ?string
    {
        if ($href === null || self::safe($href) !== null) {
            return $href;
        }

        $message = "{$where} 的網址「{$href}」用了不允許的協定：只能是 http、https、mailto、tel 或相對網址。";

        if (app()->environment('local')) {
            throw new InvalidArgumentException($message);
        }

        Log::warning($message.' 已省略這個網址。');

        return null;
    }
}
