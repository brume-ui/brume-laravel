<?php

namespace Brume\Form;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Parent-scoped JSON identity. Legacy positions initialize stable keys, persisted on first save. */
final class JsonRows
{
    public static function hydrate(array $stored, ?Model $root, string $path, array $ancestors = []): array
    {
        $rows = [];
        $keys = [];
        foreach ($stored as $index => $row) {
            if ($row instanceof \stdClass) {
                $row = get_object_vars($row);
            }
            if (! is_array($row)) {
                throw new InvalidArgumentException('Stored repeater rows must be objects.');
            }
            $identity = $row['_brume'] ?? null;
            if ($identity instanceof \stdClass) {
                $identity = get_object_vars($identity);
            }
            $key = $identity['key'] ?? 'legacy:'.hash('sha256', json_encode([$root?->getMorphClass(), $root?->getKey(), $path, $ancestors, $index]));
            if (! is_string($key) || $key === '' || ($identity !== null && array_keys($identity) !== ['key']) || isset($keys[$key])) {
                throw new InvalidArgumentException('Invalid stored JSON row identity.');
            }
            $keys[$key] = true;
            $row['_brume'] = ['key' => $key];
            $rows[] = $row;
        }

        return $rows;
    }
}
