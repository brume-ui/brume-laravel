<?php

namespace Brume\Form;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use stdClass;

/** @internal Preserve JSON container kinds without undoing middleware scalar transformations. */
final class JsonValues
{
    public static function requestValue(Request $request, string $key, mixed $default = null): mixed
    {
        $value = $request->input($key, $default);
        if (! $request->isJson()) {
            return $value;
        }
        $wire = json_decode($request->getContent());

        return self::containers($value, data_get($wire, $key));
    }

    public static function validationView(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(self::validationView(...), $value) : $value;
    }

    public static function storedValue(Model $model, string $path, mixed $value): mixed
    {
        [$root] = explode('.', $path, 2);
        // An application accessor owns its returned semantics. Only ordinary JSON casts have
        // a reliable container correspondence with the current encoded attribute state.
        if (! $model->hasCast($root, ['array', 'json', 'object']) || $model->hasGetMutator($root) || $model->hasAttributeGetMutator($root)) {
            return $value;
        }
        $raw = $model->getAttributes()[$root] ?? null;
        if (! is_string($raw)) {
            return $value;
        }
        $decoded = json_decode($raw);
        $shape = str_contains($path, '.') ? data_get($decoded, explode('.', $path, 2)[1]) : $decoded;

        return self::containers($value, $shape);
    }

    private static function containers(mixed $value, mixed $shape): mixed
    {
        if ((! is_array($value) && ! $value instanceof stdClass) || (! is_array($shape) && ! $shape instanceof stdClass)) {
            return $value;
        }
        $result = [];
        foreach ((array) $value as $key => $child) {
            $result[$key] = self::containers($child, is_array($shape) ? ($shape[$key] ?? null) : ($shape->{$key} ?? null));
        }

        return $shape instanceof stdClass ? (object) $result : $result;
    }
}
