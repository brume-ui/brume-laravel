<?php

namespace Brume\Support;

/**
 * Laravel uses zh_TW / en / zh_CN; the frontend uses zh-TW / en / zh-CN.
 */
final class LocaleMap
{
    public static function toFrontend(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }

    public static function toLaravel(string $locale): string
    {
        return str_replace('-', '_', $locale);
    }

    /**
     * Map a frontend locale (e.g. from the brume_locale cookie) to an enabled Laravel locale,
     * falling back to config('brume.locale') when it is missing or not enabled.
     */
    public static function resolve(?string $frontendLocale): string
    {
        $locale = self::toLaravel((string) $frontendLocale);

        return in_array($locale, config('brume.locales'), true)
            ? $locale
            : config('brume.locale');
    }
}
