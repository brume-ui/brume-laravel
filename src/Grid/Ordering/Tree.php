<?php

namespace Brume\Grid\Ordering;

use Illuminate\Database\Eloquent\Model;

/**
 * The hierarchy of a treeView() grid, built from its rows in sibling order (the order column
 * ascending, NULLs last, ties by key).
 *
 * A row is a root when its parent is NULL or the tree's root value, when its parent is not one of
 * the rows (an orphan: deleted, or outside the grid's query), or when it sits on a parent cycle;
 * so every row shows exactly once and nothing loops. Rows are keyed by their key as a string and
 * keep the key as the database returned it.
 *
 * @internal
 */
final class Tree
{
    /** @var array<string, array{key: int|string, parent: mixed, value: mixed, model?: Model}> */
    private array $rows = [];

    /** @var array<string, string|null> the parent each row is shown under, null for a root */
    private array $parents = [];

    /** @var array<string, list<string>> children by parent, '' holding the roots */
    private array $children = [];

    /** @var array<string, int> */
    private array $depths = [];

    /** @var array<string, int> */
    private array $heights = [];

    /** @var list<string> */
    private array $order = [];

    /**
     * @param  iterable<array{key: int|string, parent: mixed, value: mixed, model?: Model}>  $rows  in sibling order (with the model when the grid shows it)
     */
    public function __construct(iterable $rows, int|string|null $root)
    {
        foreach ($rows as $row) {
            // A join that repeats a row keeps it once.
            $this->rows[(string) $row['key']] ??= $row;
        }

        foreach ($this->rows as $id => $row) {
            $parent = $row['parent'];
            $this->parents[(string) $id] = $parent === null || ($root !== null && (string) $parent === (string) $root)
                || ! isset($this->rows[(string) $parent]) ? null : (string) $parent;
        }

        $this->breakCycles();

        $this->children[''] = [];
        foreach ($this->parents as $id => $parent) {
            $this->children[$parent ?? ''][] = (string) $id;
        }

        $this->walk('', 0);
    }

    /**
     * The rows depth first, siblings in order.
     *
     * @return list<string>
     */
    public function order(): array
    {
        return $this->order;
    }

    public function has(string $id): bool
    {
        return isset($this->rows[$id]);
    }

    /**
     * @return array{key: int|string, parent: mixed, value: mixed, model?: Model}
     */
    public function row(string $id): array
    {
        return $this->rows[$id];
    }

    public function parent(string $id): ?string
    {
        return $this->parents[$id];
    }

    /**
     * The children of $parent in order; null asks for the roots.
     *
     * @return list<string>
     */
    public function children(?string $parent): array
    {
        return $this->children[$parent ?? ''] ?? [];
    }

    /** 1 for a root. */
    public function depth(string $id): int
    {
        return $this->depths[$id];
    }

    /** The levels the row and its descendants take: 1 for a leaf. */
    public function height(string $id): int
    {
        return $this->heights[$id];
    }

    /** Whether $id is $ancestor or lies below it. */
    public function within(string $id, string $ancestor): bool
    {
        for ($current = $id; $current !== null; $current = $this->parents[$current]) {
            if ($current === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every row on a parent cycle becomes a root. Each row is walked up once (colored in-progress,
     * then done), so this is linear.
     */
    private function breakCycles(): void
    {
        $state = [];

        foreach (array_keys($this->parents) as $start) {
            $path = [];
            for ($current = (string) $start; $current !== null && ! isset($state[$current]); $current = $this->parents[$current]) {
                $state[$current] = 'walking';
                $path[] = $current;
            }

            if ($current !== null && $state[$current] === 'walking') {
                foreach (array_slice($path, array_search($current, $path, true)) as $member) {
                    $this->parents[$member] = null;
                }
            }

            foreach ($path as $id) {
                $state[$id] = 'done';
            }
        }
    }

    /**
     * Records the depth-first order and the depth and height of $parent's descendants; returns
     * the height of the tallest child.
     */
    private function walk(string $parent, int $depth): int
    {
        $tallest = 0;

        foreach ($this->children[$parent] ?? [] as $id) {
            $this->order[] = $id;
            $this->depths[$id] = $depth + 1;
            $this->heights[$id] = 1 + $this->walk($id, $depth + 1);
            $tallest = max($tallest, $this->heights[$id]);
        }

        return $tallest;
    }
}
