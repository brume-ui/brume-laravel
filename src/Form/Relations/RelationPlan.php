<?php

namespace Brume\Form\Relations;

use Brume\Form\Fields\Upload;
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

    /** Only the trusted archiver may replace validated root Upload leaves. No graph reconstruction. */
    public function withArchivedValues(array $values): self
    {
        $tree = $this->tree;
        $validated = $this->validated;
        foreach ($tree['fields'] as $path => $field) {
            if ($field instanceof Upload && Arr::has($validated, $path)) {
                Arr::set($tree['values'], $path, Arr::get($values, $path));
                Arr::set($validated, $path, Arr::get($values, $path));
            }
        }

        return new self($tree, $validated, $this->inventory, $this->target);
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
