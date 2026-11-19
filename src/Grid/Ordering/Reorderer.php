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
 * whole order at once (ReorderListRequest), and the rows of the 調整順序 dialog (GET).
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
     * @param  array<string, mixed>  $request  a ReorderMoveRequest
     * @return array{undo?: array{id: int|string, before?: int|string, after?: int|string}}
     *
     * @throws ValidationException 422: no valid id, both before and after, a parent (list mode), or the row as its own anchor
     * @throws ReorderConflict 409: the row or its anchor is not in the grid's query
     */
    public function move(Grid $grid, array $request): array
    {
        $rows = $grid->rows();

        if (array_key_exists('before', $request) && array_key_exists('after', $request)) {
            throw ValidationException::withMessages(['before' => __('brume::grid.reorder.both_anchors')]);
        }

        if (array_key_exists('parent', $request)) {
            throw ValidationException::withMessages(['parent' => __('brume::grid.reorder.parent')]);
        }

        $id = $this->validId($rows, $request['id'] ?? null, 'id');
        $side = array_key_exists('before', $request) ? 'before' : (array_key_exists('after', $request) ? 'after' : null);
        $anchor = $side !== null ? $this->validId($rows, $request[$side], $side) : null;

        if ($anchor !== null && (string) $anchor === (string) $id) {
            throw ValidationException::withMessages([$side => __('brume::grid.reorder.self')]);
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
            $old = $current[$id]['value'];

            if (is_numeric($old) && (float) $old === (float) $value) {
                continue;
            }

            $model->newModelQuery()->whereKey($current[$id]['key'])->toBase()->update([$column => $value]);
            $changed[] = $current[$id]['key'];
        }

        if ($changed !== [] && ($hook = $grid->orderSavedHook()) !== null) {
            $hook($changed);
        }
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
