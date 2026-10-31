<?php

namespace Brume\Form\Relations;

use Brume\Form\Fields\Repeater;
use Brume\Form\Fields\Upload;
use Brume\Uploads\UploadInventory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/** Immutable operation tree. Models are execution targets; values and ownership decisions are frozen. */
final readonly class RelationPlan
{
    private array $tree;

    private array $validated;

    private array $inventory;

    private Model $target;

    public function __construct(array $tree, array $validated, array $inventory, ?Model $target = null)
    {
        $this->target = $target ?? $tree['model'];
        $this->tree = self::copy($tree);
        $this->validated = self::copy($validated);
        $this->inventory = self::copy($inventory);
    }

    /** Synchronizer copies the saved root back to the caller's model; decisions stay immutable. */
    public function acceptSavedRoot(Model $saved): void
    {
        $this->target->setRawAttributes($saved->getAttributes(), true);
        $this->target->exists = $saved->exists;
        $this->target->wasRecentlyCreated = $saved->wasRecentlyCreated;
    }

    public function withOriginalInventory(array $original): self
    {
        $known = [];
        foreach ($original as $entry) {
            if (($owner = UploadInventory::owner($entry['owner'])) !== null) {
                $known[$owner] = true;
            }
        }
        foreach ($this->inventory['original'] as $entry) {
            $owner = UploadInventory::owner($entry['owner']);
            if ($owner !== null && ! isset($known[$owner])) {
                $original[] = $entry;
            }
        }

        return new self($this->tree, $this->validated, array_replace($this->inventory, ['original' => $original]), $this->target);
    }

    public function values(): array
    {
        return self::copy($this->validated);
    }

    public function inventory(): array
    {
        return self::copy($this->inventory);
    }

    public function operations(): array
    {
        return self::copy($this->tree);
    }

    /** Compose archived leaves into frozen operations, including JSON aggregates, without replanning. */
    public function withArchivedValues(array $values): self
    {
        $validated = $this->validated;
        foreach ($this->inventory['fields'] ?? [] as $path => $entry) {
            if ($entry['field'] instanceof Upload && ($entry['validated'] ?? false) && Arr::has($validated, $path) && Arr::has($values, $path)) {
                Arr::set($validated, $path, Arr::get($values, $path));
            }
        }
        $values = $validated;
        $original = UploadInventory::originals($this->inventory);
        $compose = function (array $tree) use (&$compose, $values, $original): array {
            foreach ($tree['fields'] as $relative => $field) {
                $path = ($tree['prefix'] ?? '').$relative;
                if (($field instanceof Upload || $field instanceof Repeater) && Arr::has($values, $path)) {
                    Arr::set($tree['values'], $relative, Arr::get($values, $path));
                }
                if ($field instanceof Repeater && $field->relationName() === null) {
                    $entry = $this->inventory['fields'][$path] ?? null;
                    $tree['jsonOriginals'][$relative] = $entry === null ? [] : ($original[UploadInventory::key($entry)]['stored'] ?? []);
                    $tree['jsonContexts'][$relative] = $entry['storageContext'] ?? [];
                    $tree['jsonTemplates'][$relative] = $entry['template'] ?? $path;
                }
            }
            foreach ($tree['relations'] as &$relation) {
                if ($relation['kind'] === 'belongsTo') {
                    if ($relation['row'] !== null) {
                        $relation['row'] = $compose($relation['row']);
                    }
                } else {
                    $relation['rows'] = array_map($compose, $relation['rows']);
                }
            }

            return $tree;
        };

        return new self($compose($this->tree), $values, $this->inventory, $this->target);
    }

    private static function copy(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::copy(...), $value);
        }
        if ($value instanceof \stdClass) {
            return (object) array_map(self::copy(...), get_object_vars($value));
        }

        return is_object($value) && ! $value instanceof \Closure ? clone $value : $value;
    }
}
