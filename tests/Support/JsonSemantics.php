<?php

namespace Brume\Tests\Support;

use stdClass;

/** Ignore only JSON whitespace/object property order; retain containers, list order and scalar types. */
final class JsonSemantics
{
    public static function canonical(string $json): string
    {
        return json_encode(self::sort(json_decode($json, false, 512, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sort(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map(self::sort(...), $properties);
        }

        return is_array($value) ? array_map(self::sort(...), $value) : $value;
    }
}
