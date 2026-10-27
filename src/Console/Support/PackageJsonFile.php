<?php

namespace Brume\Console\Support;

use InvalidArgumentException;
use stdClass;

/**
 * Reads and writes a package.json while keeping the project's formatting: indentation
 * (tabs or N spaces), line endings, trailing newline and key order.
 */
final class PackageJsonFile
{
    private const SECTIONS = ['dependencies', 'devDependencies', 'scripts'];

    /**
     * Objects stay stdClass so empty {} survive a round trip.
     *
     * @throws InvalidArgumentException with a message that says how to fix the file
     */
    public static function parse(string $json, string $label = 'package.json'): stdClass
    {
        $decoded = json_decode($json, false);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException("{$label} 不是有效的 JSON（".json_last_error_msg().'），請先修正再執行。');
        }

        if (! $decoded instanceof stdClass) {
            throw new InvalidArgumentException("{$label} 的最外層必須是物件 {…}，請先修正再執行。");
        }

        foreach (self::SECTIONS as $section) {
            if (isset($decoded->{$section}) && ! $decoded->{$section} instanceof stdClass) {
                throw new InvalidArgumentException("{$label} 的 \"{$section}\" 必須是物件 {…}，請先修正再執行。");
            }
        }

        return $decoded;
    }

    /** Encode $package in the style of $original (2 spaces and LF for a new file). */
    public static function format(stdClass $package, ?string $original = null): string
    {
        $indent = '  ';

        if ($original !== null && preg_match('/^([ \t]+)"/m', $original, $match)) {
            $indent = $match[1];
        }

        $json = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $json = preg_replace_callback('/^(?: {4})+/m', fn (array $m) => str_repeat($indent, strlen($m[0]) / 4), $json);

        $original ??= "\n";
        $json .= preg_match('/\R\z/', $original) ? "\n" : '';

        return str_contains($original, "\r\n") ? str_replace("\n", "\r\n", $json) : $json;
    }
}
