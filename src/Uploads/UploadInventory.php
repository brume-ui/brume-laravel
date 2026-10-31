<?php

namespace Brume\Uploads;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Persisted storage identity never comes from draft numeric positions or source wire IDs. */
final class UploadInventory
{
    public static function owner(?Model $owner): ?string
    {
        if ($owner === null || ! $owner->exists) {
            return null;
        }
        $identity = $owner->getRawOriginal($owner->getKeyName());
        if ($owner instanceof Pivot && $identity === null) {
            $identity = [$owner->getForeignKey() => $owner->getRawOriginal($owner->getForeignKey()), $owner->getRelatedKey() => $owner->getRawOriginal($owner->getRelatedKey())];
        }

        return json_encode([$owner->getConnection()->getName(), $owner->getTable(), $identity], JSON_THROW_ON_ERROR);
    }

    public static function key(array $entry): string
    {
        return json_encode([self::owner($entry['owner']), $entry['template'], $entry['storageContext'] ?? $entry['rowContext']], JSON_THROW_ON_ERROR);
    }

    public static function originals(array $inventory): array
    {
        $result = [];
        foreach ($inventory['original'] ?? [] as $entry) {
            $result[self::key($entry)] ??= $entry;
        }

        return $result;
    }
}
