<?php

namespace Brume\Uploads;

use Brume\Form\Fields\RelationPicker;
use Brume\Form\Fields\Repeater;
use Brume\Form\Fields\Upload;
use Brume\Form\Form;
use Brume\Form\JsonValues;
use Brume\Uploads\Support\UploadTransactions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/** Trusted persisted file inventory, independent of editable/visible form fields. */
final class StoredUploadCleanup
{
    public function capture(Form $form, Model $record): array
    {
        $stored = clone $record;
        $stored->setRawAttributes($record->getRawOriginal(), true);

        return $this->walk($form, $stored, null);
    }

    private function walk(Form $form, ?Model $owner, ?array $json): array
    {
        $files = [];
        foreach ($form->fields() as $field) {
            $value = $json !== null ? data_get($json, $field->attributeName()) : ($owner === null ? null : JsonValues::storedValue($owner, $field->attributeName(), data_get($owner, $field->attributeName())));
            if ($field instanceof Upload) {
                $files = [...$files, ...$this->files($field, $value)];
            }
            if (! $field instanceof Repeater && ! $field instanceof RelationPicker) {
                continue;
            }
            if ($field->relationName() === null) {
                foreach ($value ?? [] as $row) {
                    $files = [...$files, ...$this->walk($field->rowForm(), $owner, $row instanceof \stdClass ? get_object_vars($row) : $row)];
                }
            } elseif ($owner !== null && $owner->exists) {
                $relation = $field->relationship($owner);
                // Source associations survive detach/root deletion; only owned rows/pivots clean up.
                if ($relation instanceof BelongsTo || ($field instanceof RelationPicker && $relation instanceof HasMany && $field->associationAttribute() === null)) {
                    continue;
                }
                if ($relation->getRelated()->getConnection()->getName() !== $owner->getConnection()->getName()) {
                    throw new \LogicException('Mixed upload connections are not supported.');
                }
                foreach ($relation->lockForUpdate()->get() as $child) {
                    $files = [...$files, ...$this->walk($field->rowForm(), $relation instanceof BelongsToMany ? $child->getRelation($relation->getPivotAccessor()) : $child, null)];
                }
            }
        }

        return $files;
    }

    public function files(Upload $field, mixed $value): array
    {
        $files = [];
        foreach ($field->settings()['multiple'] ? (is_array($value) ? $value : []) : [$value] as $item) {
            $path = is_string($item) ? $item : data_get($item, 'path');
            if (is_string($path) && $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '\\') && ! preg_match('/[\x00-\x1f\x7f]/', $path) && ! in_array('..', explode('/', $path), true) && ! in_array('.', explode('/', $path), true)) {
                $files[] = [$field->diskName(), $path];
            }
        }

        return $files;
    }

    /** Compare verified current membership to original raw ownership; omitted containers retain files. */
    public function removed(array $inventory): array
    {
        $sourceBoundaries = [];
        foreach ($inventory['original'] as $container) {
            if (! $container['field'] instanceof RelationPicker || $container['field']->relationName() === null || $container['owner'] === null) {
                continue;
            }
            $relation = $container['field']->relationship($container['owner']);
            if ($relation instanceof BelongsTo || ($relation instanceof HasMany && $container['field']->associationAttribute() === null)) {
                $sourceBoundaries[json_encode([$container['template'], $container['rowContext']], JSON_THROW_ON_ERROR)] = true;
            }
        }
        $current = [];
        foreach ($inventory['fields'] ?? [] as $entry) {
            $current[UploadInventory::key($entry)] = $entry;
        }
        $files = [];
        foreach ($inventory['original'] as $entry) {
            if (! $entry['field'] instanceof Upload || isset($current[UploadInventory::key($entry)])) {
                continue;
            }
            $remove = false;
            foreach ($inventory['removed'] ?? [] as $removed) {
                if ($removed['kind'] === 'direct' || (method_exists($removed['owner'], 'trashed') && ! $removed['owner']->isForceDeleting())) {
                    continue;
                }
                $prefix = $removed['rowContext'];
                $row = $entry['rowContext'][count($prefix)] ?? null;
                if (array_slice($entry['rowContext'], 0, count($prefix)) === $prefix && ($row['path'] ?? null) === $removed['template'] && ($row['id'] ?? null) === $removed['identity']) {
                    $owned = true;
                    foreach (array_slice($entry['rowContext'], count($prefix) + 1, null, true) as $index => $descendant) {
                        if (isset($sourceBoundaries[json_encode([$descendant['path'], array_slice($entry['rowContext'], 0, $index)], JSON_THROW_ON_ERROR)])) {
                            $owned = false;
                        }
                    }
                    $remove = $remove || $owned;
                }
            }
            foreach ($entry['rowContext'] as $index => $row) {
                $prefix = array_slice($entry['rowContext'], 0, $index);
                foreach ($inventory['fields'] ?? [] as $container) {
                    if (! $container['field'] instanceof Repeater || $container['field']->relationName() !== null || ! ($container['validated'] ?? false) || ! ($container['active'] ?? false) || $container['template'] !== $row['path'] || ($container['storageContext'] ?? $container['rowContext']) !== $prefix) {
                        continue;
                    }
                    $keys = array_map(fn ($item) => data_get($item, '_brume.key'), $container['value'] ?? []);
                    if (! in_array($row['key'], $keys, true)) {
                        $remove = true;
                    }
                }
            }
            if ($remove) {
                $files = [...$files, ...$this->files($entry['field'], $entry['stored'])];
            }
        }

        return array_values(array_unique($files, SORT_REGULAR));
    }

    public function afterHardDelete(Model $record, array $files): void
    {
        if ($files === [] || method_exists($record, 'isForceDeleting') && ! $record->isForceDeleting()) {
            return;
        }
        app(UploadTransactions::class)->register($record->getConnection(),
            fn () => $this->deleteFiles($files),
            fn () => null,
        );
    }

    /** A completed database operation must not become a failed request on storage errors. */
    public function deleteFiles(array $files): void
    {
        foreach ($files as [$disk, $path]) {
            try {
                $filesystem = Storage::disk($disk);
                if ($filesystem->exists($path) && ! $filesystem->delete($path) && $filesystem->exists($path)) {
                    throw new \RuntimeException('Unable to clean upload file: '.$path);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
