<?php

namespace Brume\Support;

use InvalidArgumentException;

final class Color
{
    public const DEFAULT_ACCENT = '#0e7490';

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1;
    }

    /**
     * The configured accent if it is a hex color, else the default. It ends up in an inline
     * style on <html>, so anything else must never reach the page.
     */
    public static function accent(): string
    {
        $accent = config('brume.layout.accent');

        return self::isHex($accent) ? $accent : self::DEFAULT_ACCENT;
    }

    /**
     * WCAG 2.x contrast ratio between two hex colors (#rgb or #rrggbb), from 1.0 to 21.0.
     */
    public static function contrast(string $fgHex, string $bgHex): float
    {
        $a = self::luminance($fgHex);
        $b = self::luminance($bgHex);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            throw new InvalidArgumentException("「#{$hex}」不是有效的色碼，請使用 #rgb 或 #rrggbb 格式。");
        }

        [$r, $g, $b] = array_map(function (string $channel): float {
            $c = hexdec($channel) / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
