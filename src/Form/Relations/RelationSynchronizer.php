<?php

namespace Brume\Form\Relations;

use Brume\Form\Fields\RelationPicker;
use Brume\Form\Fields\Repeater;
use Brume\Form\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;

final class RelationSynchronizer
{
    private ?Model $rootContext;

    public function save(RelationPlan $plan): void
    {
        $tree = $plan->operations();
        $this->rootContext = $tree['model']->exists ? $tree['model'] : null;
        $this->node($tree);
        $plan->acceptSavedRoot($tree['model']);
    }

    private function node(array $node, ?Model $parent = null, ?array $binding = null, ?Form $form = null): void
    {
        $model = $node['model'];
        foreach ($node['fields'] as $path => $field) {
            if (Arr::has($node['values'], $path)) {
                if (($field instanceof Repeater || $field instanceof RelationPicker) && isset($node['jsonOriginals'][$path])) {
                    $field->fillWithOriginal($model, Arr::get($node['values'], $path), $node['jsonOriginals'][$path], $this->rootContext, $node['jsonTemplates'][$path], $node['jsonContexts'][$path]);
                } else {
                    $field->fill($model, Arr::get($node['values'], $path));
                }
            }
        }
        if ($binding !== null) {
            $model->setAttribute($binding['foreign'], $parent->getAttribute($binding['local']));
            if (isset($binding['association'])) {
                $model->setAttribute($binding['association'], $binding['selected']);
            }
            if (isset($binding['order'])) {
                $model->setAttribute($binding['order'], $binding['position']);
            }
        }
        if (isset($node['ordering'])) {
            $model->setAttribute($node['ordering']['attribute'], $node['ordering']['position']);
        }
        foreach ($node['relations'] as $operation) {
            if ($operation['kind'] === 'belongsTo') {
                if ($operation['row'] !== null && ($operation['row']['fields'] !== [] || $operation['row']['relations'] !== [])) {
                    $this->node($operation['row']);
                }
                $model->setAttribute($operation['foreign'], $operation['selected']);
            }
        }
        $trashedAt = null;
        if ($node['restore']) {
            $trashedAt = $model->getRawOriginal($model->getDeletedAtColumn());
            $model->setAttribute($model->getDeletedAtColumn(), null);
        }
        if (! $model->save()) {
            throw new \LogicException('The model refused form persistence.');
        }
        if ($trashedAt !== null && $form !== null) {
            $this->restoreNested($model, $form, $trashedAt);
        }
        foreach ($node['relations'] as $operation) {
            if ($operation['kind'] === 'belongsTo') {
                continue;
            }
            if ($operation['kind'] === 'pivot') {
                $relation = $model->{$operation['name']}();
                foreach ($operation['removed'] as $removed) {
                    $relation->detach($removed);
                }
                foreach ($operation['rows'] as $row) {
                    $pivot = $row['model'];
                    // Keep every undeclared pivot attribute, while binding identities server-side.
                    $pivot->setAttribute($relation->getForeignPivotKeyName(), $model->getAttribute($relation->getParentKeyName()));
                    $pivot->setAttribute($relation->getRelatedPivotKeyName(), $row['relatedKey']);
                    $this->node($row);
                }

                continue;
            }
            if ($operation['kind'] === 'direct') {
                foreach ($operation['removed'] as $removed) {
                    $removed->setAttribute($operation['foreign'], null);
                    if (! $removed->save()) {
                        throw new \LogicException('The model refused deselection.');
                    }
                }
            } else {
                // A row trashed by an earlier removal stays as it is (and keeps its removal time).
                foreach ($operation['removed'] as $removed) {
                    if (! $this->trashed($removed)) {
                        $this->remove($removed, $operation['form'], $this->softDeletes($removed));
                    }
                }
            }
            foreach ($operation['rows'] as $row) {
                $this->node($row, $model, $row['binding'], $operation['form']);
            }
        }
    }

    /**
     * A removed owned row takes its owned nested rows with it through declared nested relation
     * repeaters and associateBy() intermediaries; BelongsTo and direct HasMany selections are sources
     * and stay as they are.
     *
     * A row removed by a hard delete purges its subtree, deepest first: nested rows are deleted by
     * their own model (trashed where it uses SoftDeletes) and BelongsToMany pivots detach. A row
     * removed by a soft delete stays restorable: it is trashed first, then only its soft-deletable
     * nested rows are trashed (recursively, so their deleted_at is never earlier than the parent's),
     * while other nested rows and pivots stay attached to the trashed row.
     */
    private function remove(Model $row, Form $form, bool $restorable): void
    {
        if ($restorable) {
            $this->delete($row);
        }
        foreach ($form->fields() as $field) {
            if (! ($field instanceof Repeater || $field instanceof RelationPicker) || $field->relationName() === null) {
                continue;
            }
            $relation = $field->relationship($row);
            if ($relation instanceof BelongsToMany) {
                if (! $restorable) {
                    $relation->detach();
                }
            } elseif ($relation instanceof HasMany && ($field instanceof Repeater || $field->associationAttribute() !== null)) {
                if ($restorable && ! $this->softDeletes($relation->getRelated())) {
                    continue;
                }
                foreach ($relation->lockForUpdate()->get() as $child) {
                    $this->remove($child, $field->rowForm(), $restorable);
                }
            }
        }
        if (! $restorable) {
            $this->delete($row);
        }
    }

    private function delete(Model $row): void
    {
        if (! $row->delete()) {
            throw new \LogicException('The model refused removal.');
        }
    }

    /**
     * Restoring a removed row also restores the soft-deletable nested rows its removal trashed:
     * those trashed at or after the row itself (see remove()). Rows trashed earlier on their own stay
     * trashed.
     */
    private function restoreNested(Model $row, Form $form, string $trashedAt): void
    {
        foreach ($form->fields() as $field) {
            if (! ($field instanceof Repeater || $field instanceof RelationPicker) || $field->relationName() === null) {
                continue;
            }
            $relation = $field->relationship($row);
            if (! $relation instanceof HasMany || ! ($field instanceof Repeater || $field->associationAttribute() !== null) || ! $this->softDeletes($relation->getRelated())) {
                continue;
            }
            $related = $relation->getRelated();
            foreach ($relation->onlyTrashed()->where($related->getQualifiedDeletedAtColumn(), '>=', $trashedAt)->lockForUpdate()->get() as $child) {
                $childTrashedAt = $child->getRawOriginal($child->getDeletedAtColumn());
                if (! $child->restore()) {
                    throw new \LogicException('The model refused restoration.');
                }
                $this->restoreNested($child, $field->rowForm(), $childTrashedAt);
            }
        }
    }

    private function softDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true) && ! $model->isForceDeleting();
    }

    private function trashed(Model $model): bool
    {
        return $this->softDeletes($model) && $model->trashed();
    }
}
