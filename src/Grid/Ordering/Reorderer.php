<?php

namespace Brume\Grid\Ordering;

use BackedEnum;
use Brume\Grid\Grid;
use Brume\Grid\RowLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Stringable;

/**
 * Answers endpoints.reorder of an orderable() grid: a move of one row (ReorderMoveRequest), the
 * whole order at once (ReorderListRequest), and the rows of the 調整順序 dialog (GET). A treeView()
 * grid takes moves only (see move()), each between or within sibling groups.
 *
 * The scope is the grid's query as given to Grid::make() (conditions, global scopes, soft deletes),
 * never the list's search, filters or sort. Its current order is the order column in its direction,
 * NULLs last, ties by ascending primary key. Every save renumbers the whole scope 1..N (with 'desc'
 * the first row gets N) inside one transaction that reads the scope with a lock, writes only the
 * rows whose value changes through the query builder (no model events, updated_at untouched), then
 * calls the orderSaved() hook with those rows' ids.
 *
 * Ids are compared as strings and handed out as the database returns them, so a string key is
 * never cast to a number.
 */
class Reorderer
{
    /** The most rows the dialog lists or one whole-order save takes; a move has no cap. */
    public const MAX_ITEMS = 1000;

    /**
     * @return array{items: list<array{id: int|string, label: string}>}
     *
     * @throws ValidationException 422: the scope has more than MAX_ITEMS rows
     */
    public function items(Grid $grid): array
    {
        $label = $grid->ordering()['label'];
        $models = $grid->applyOrdering($grid->rows()->query())->limit(self::MAX_ITEMS + 1)->get()->unique();

        if ($models->count() > self::MAX_ITEMS) {
            throw $this->tooMany();
        }

        return ['items' => $models->map(fn (Model $model) => [
            'id' => $model->getKey(),
            'label' => $this->label(data_get($model, $label)),
        ])->values()->all()];
    }

    /**
     * Moves the row `id` right after `after`, right before `before`, or to the end. The answer's
     * undo is the move that puts the row back (before its old next row, or after its old previous
     * row when it was last); a scope of one row has none.
     *
     * In a tree the move names the new `parent` (null for a root), the anchor is one of its
     * children, and neither anchor means its last child. The undo always names the old parent.
     *
     * @param  array<string, mixed>  $request  a ReorderMoveRequest
     * @return array{undo?: array{id: int|string, parent?: int|string|null, before?: int|string, after?: int|string}}
     *
     * @throws ValidationException 422: no valid id, both before and after, a parent (list mode) or none (tree), the row as its own anchor, a parent below the row, or a tree deeper than maxDepth
     * @throws ReorderConflict 409: the row, its anchor or its parent is not in the grid's query, or the anchor is not a child of the parent
     */
    public function move(Grid $grid, array $request): array
    {
        $rows = $grid->rows();

        if (array_key_exists('before', $request) && array_key_exists('after', $request)) {
            throw ValidationException::withMessages(['before' => __('brume::grid.reorder.both_anchors')]);
        }

        $tree = $grid->ordering()['mode'] === 'tree';

        if (! $tree && array_key_exists('parent', $request)) {
            throw ValidationException::withMessages(['parent' => __('brume::grid.reorder.parent')]);
        }

        if ($tree && ! array_key_exists('parent', $request)) {
            throw ValidationException::withMessages(['parent' => __('brume::grid.reorder.tree_parent')]);
        }

        $id = $this->validId($rows, $request['id'] ?? null, 'id');
        $side = array_key_exists('before', $request) ? 'before' : (array_key_exists('after', $request) ? 'after' : null);
        $anchor = $side !== null ? $this->validId($rows, $request[$side], $side) : null;

        if ($anchor !== null && (string) $anchor === (string) $id) {
            throw ValidationException::withMessages([$side => __('brume::grid.reorder.self')]);
        }

        if ($tree) {
            $parent = $request['parent'] === null ? null : $this->validId($rows, $request['parent'], 'parent');

            if ($parent !== null && (string) $parent === (string) $id) {
                throw ValidationException::withMessages(['parent' => __('brume::grid.reorder.descendant')]);
            }

            return $this->transaction($rows, fn () => $this->moveInTree($grid, $rows, (string) $id, $parent !== null ? (string) $parent : null, $side, $anchor !== null ? (string) $anchor : null));
        }

        return $this->transaction($rows, function () use ($grid, $rows, $id, $side, $anchor) {
            $current = $this->current($grid, $rows);
            // PHP turns numeric string keys into ints: back to strings for the strict searches.
            $order = array_map('strval', array_keys($current));
            $from = array_search((string) $id, $order, true);

            if ($from === false || ($anchor !== null && ! array_key_exists((string) $anchor, $current))) {
                throw ReorderConflict::make();
            }

            $moved = $current[$order[$from]]['key'];
            $undo = match (true) {
                isset($order[$from + 1]) => ['id' => $moved, 'before' => $current[$order[$from + 1]]['key']],
                $from > 0 => ['id' => $moved, 'after' => $current[$order[$from - 1]]['key']],
                default => null,
            };

            array_splice($order, $from, 1);
            $to = match ($side) {
                'before' => array_search((string) $anchor, $order, true),
                'after' => array_search((string) $anchor, $order, true) + 1,
                default => count($order),
            };
            array_splice($order, $to, 0, [(string) $id]);

            $this->write($grid, $rows, $current, $order);

            return $undo !== null ? ['undo' => $undo] : [];
        });
    }

    /**
     * Saves $ids, exactly the rows of the grid's query, as the new order.
     *
     * @param  list<mixed>  $ids
     *
     * @throws ValidationException 422: more than MAX_ITEMS ids, or one that cannot be a key
     * @throws ReorderConflict 409: the ids are not exactly the rows of the grid's query
     */
    public function saveList(Grid $grid, array $ids): void
    {
        $rows = $grid->rows();

        if (count($ids) > self::MAX_ITEMS) {
            throw $this->tooMany();
        }

        $order = array_map(fn (mixed $id) => (string) $this->validId($rows, $id, 'ids'), array_values($ids));

        $this->transaction($rows, function () use ($grid, $rows, $order) {
            $current = $this->current($grid, $rows);

            if (count($order) !== count($current) || count(array_unique($order)) !== count($order)
                || array_diff($order, array_map('strval', array_keys($current))) !== []) {
                throw ReorderConflict::make();
            }

            $this->write($grid, $rows, $current, $order);
        });
    }

    /**
     * Moves $id under $parent (null: a root), before or after its child $anchor or last. Only
     * the old and the new siblings are renumbered 1..N; the row itself also gets its new parent,
     * written as the tree's root value for a root.
     *
     * @return array{undo: array{id: int|string, parent: int|string|null, before?: int|string, after?: int|string}}
     */
    protected function moveInTree(Grid $grid, RowLookup $rows, string $id, ?string $parent, ?string $side, ?string $anchor): array
    {
        ['column' => $column, 'parent' => $parentColumn, 'maxDepth' => $maxDepth, 'root' => $root] = $grid->ordering();
        $tree = $this->currentTree($grid, $rows);

        if (! $tree->has($id) || ($parent !== null && ! $tree->has($parent))) {
            throw ReorderConflict::make();
        }

        if ($parent !== null && $tree->within($parent, $id)) {
            throw ValidationException::withMessages(['parent' => __('brume::grid.reorder.descendant')]);
        }

        if ($anchor !== null && (! $tree->has($anchor) || $tree->parent($anchor) !== $parent)) {
            throw ReorderConflict::make();
        }

        if ($maxDepth !== null && ($parent !== null ? $tree->depth($parent) : 0) + $tree->height($id) > $maxDepth) {
            throw ValidationException::withMessages(['parent' => __('brume::grid.reorder.max_depth', ['max' => $maxDepth])]);
        }

        $key = fn (?string $node) => $node !== null ? $tree->row($node)['key'] : null;
        $oldParent = $tree->parent($id);
        $old = $tree->children($oldParent);
        $from = array_search($id, $old, true);
        $undo = ['id' => $key($id), 'parent' => $key($oldParent)] + match (true) {
            isset($old[$from + 1]) => ['before' => $key($old[$from + 1])],
            $from > 0 => ['after' => $key($old[$from - 1])],
            default => [],
        };

        array_splice($old, $from, 1);
        $new = $oldParent === $parent ? $old : $tree->children($parent);
        $to = match ($side) {
            'before' => array_search($anchor, $new, true),
            'after' => array_search($anchor, $new, true) + 1,
            default => count($new),
        };
        array_splice($new, $to, 0, [$id]);

        $storedParent = $parent !== null ? $key($parent) : $root;
        $model = $rows->query()->getModel();
        $changed = [];

        foreach ($oldParent === $parent ? [$new] : [$old, $new] as $siblings) {
            foreach ($siblings as $index => $node) {
                $row = $tree->row($node);
                $values = $this->sameValue($row['value'], $index + 1) ? [] : [$column => $index + 1];

                if ($node === $id && ! $this->sameParent($row['parent'], $storedParent)) {
                    $values[$parentColumn] = $storedParent;
                }

                if ($values !== []) {
                    $model->newModelQuery()->whereKey($row['key'])->toBase()->update($values);
                    $changed[] = $row['key'];
                }
            }
        }

        $this->saved($grid, $changed);

        return ['undo' => $undo];
    }

    /**
     * The tree of the scope, read with a lock (see Tree for how roots, orphans and cycles show).
     */
    protected function currentTree(Grid $grid, RowLookup $rows): Tree
    {
        ['column' => $column, 'parent' => $parent, 'root' => $root] = $grid->ordering();
        $query = $grid->applyOrdering($rows->query());
        $records = $query->toBase()
            ->select([
                $query->getModel()->getQualifiedKeyName().' as brume_key',
                $query->qualifyColumn($column).' as brume_order',
                $query->qualifyColumn($parent).' as brume_parent',
            ])
            ->lockForUpdate()
            ->get();

        return new Tree($records->map(fn (object $record) => [
            'key' => $rows->normalizeId($record->brume_key) ?? $record->brume_key,
            'parent' => $record->brume_parent,
            'value' => $record->brume_order,
        ]), $root);
    }

    /**
     * The scope in its current order, read with a lock: the key as the database returns it and
     * the order value, keyed by the key as a string (each row once, should a join repeat it; PHP
     * stores a numeric string key as an int, which string lookups still find).
     *
     * @return array<string, array{key: int|string, value: mixed}>
     */
    protected function current(Grid $grid, RowLookup $rows): array
    {
        $query = $grid->applyOrdering($rows->query());
        $records = $query->toBase()
            ->select([
                $query->getModel()->getQualifiedKeyName().' as brume_key',
                $query->qualifyColumn($grid->ordering()['column']).' as brume_order',
            ])
            ->lockForUpdate()
            ->get();

        $current = [];
        foreach ($records as $record) {
            $key = $rows->normalizeId($record->brume_key) ?? $record->brume_key;
            $current[(string) $key] ??= ['key' => $key, 'value' => $record->brume_order];
        }

        return $current;
    }

    /**
     * Numbers $order 1..N (N..1 for 'desc'), updates the rows whose value changes and hands their
     * ids to the orderSaved() hook.
     *
     * @param  array<string, array{key: int|string, value: mixed}>  $current
     * @param  list<string>  $order
     */
    protected function write(Grid $grid, RowLookup $rows, array $current, array $order): void
    {
        ['column' => $column, 'direction' => $direction] = $grid->ordering();
        $model = $rows->query()->getModel();
        $count = count($order);
        $changed = [];

        foreach ($order as $index => $id) {
            $value = $direction === 'asc' ? $index + 1 : $count - $index;

            if ($this->sameValue($current[$id]['value'], $value)) {
                continue;
            }

            $model->newModelQuery()->whereKey($current[$id]['key'])->toBase()->update([$column => $value]);
            $changed[] = $current[$id]['key'];
        }

        $this->saved($grid, $changed);
    }

    /**
     * Hands the ids of the rows that changed to the orderSaved() hook, when any did.
     *
     * @param  list<int|string>  $changed
     */
    protected function saved(Grid $grid, array $changed): void
    {
        if ($changed !== [] && ($hook = $grid->orderSavedHook()) !== null) {
            $hook($changed);
        }
    }

    /** A stored order value equal to $value ("3.00" counts as 3). */
    protected function sameValue(mixed $stored, int $value): bool
    {
        return is_numeric($stored) && (float) $stored === (float) $value;
    }

    /** A stored parent value equal to $parent, compared as strings (NULL only equals NULL). */
    protected function sameParent(mixed $stored, int|string|null $parent): bool
    {
        return $stored === null || $parent === null ? $stored === $parent : (string) $stored === (string) $parent;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function transaction(RowLookup $rows, callable $callback): mixed
    {
        return $rows->query()->getConnection()->transaction($callback);
    }

    /**
     * @throws ValidationException
     */
    protected function validId(RowLookup $rows, mixed $value, string $field): int|string
    {
        return $rows->normalizeId($value)
            ?? throw ValidationException::withMessages([$field => __('brume::grid.invalid_id')]);
    }

    protected function tooMany(): ValidationException
    {
        return ValidationException::withMessages(['ids' => __('brume::grid.reorder.too_many')]);
    }

    protected function label(mixed $value): string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => '',
        };
    }
}
