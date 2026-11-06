<?php

namespace Brume\Form\Relations;

use Brume\Form\Fields\RelationPicker;
use Brume\Form\Fields\Repeater;
use Brume\Form\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;

final class RelationSynchronizer
{
    /** Removal modes, see remove(). */
    private const RESTORABLE = 'restorable';

    private const PURGE = 'purge';

    private const FORCE = 'force';

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
            // Rows this save itself keeps or removes under the restored row follow the plan instead.
            $planned = [];
            foreach ($node['relations'] as $operation) {
                if ($operation['kind'] === 'children') {
                    foreach ([...$operation['removed'], ...array_column($operation['rows'], 'model')] as $row) {
                        if ($row->exists) {
                            $planned[$operation['name']][] = (string) $row->getKey();
                        }
                    }
                }
            }
            $this->restoreNested($model, $form, $trashedAt, $planned);
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
                $removed = array_values(array_filter($operation['removed'], fn (Model $row) => ! $this->trashed($row)));
                if ($removed !== []) {
                    $this->remove($removed, $operation['form'], $this->softDeletes($removed[0]) ? self::RESTORABLE : self::PURGE);
                }
            }
            foreach ($operation['rows'] as $row) {
                $this->node($row, $model, $row['binding'], $operation['form']);
            }
        }
    }

    /**
     * Removed owned rows (all of one relation, so of one model) take their owned nested rows with
     * them through declared nested relation repeaters and associateBy() intermediaries; BelongsTo and
     * direct HasMany selections are sources and stay as they are. Each nested relation is handled for
     * the whole batch with one locked query.
     *
     * RESTORABLE (a row removed by a soft delete): the row is trashed first, then only its
     * soft-deletable nested rows are trashed (recursively, so their deleted_at is never earlier than
     * the parent's); other nested rows and pivots stay attached to the trashed row.
     * PURGE (a row removed by a hard delete): its subtree goes deepest first. Nested rows are deleted
     * by their own model (trashed where it uses SoftDeletes) and pivots detach; nested rows that were
     * already trashed would be left pointing at the deleted row, so they are force-deleted (FORCE).
     * FORCE: the row and everything under it, trashed or not, is physically deleted.
     *
     * @param  list<Model>  $rows
     */
    private function remove(array $rows, Form $form, string $mode): void
    {
        if ($mode === self::RESTORABLE) {
            $this->delete($rows, false);
        }
        foreach ($form->fields() as $field) {
            if (! ($field instanceof Repeater || $field instanceof RelationPicker) || $field->relationName() === null) {
                continue;
            }
            // Built without its parent constraint, so the batch's keys constrain it instead.
            $relation = Relation::noConstraints(fn () => $field->relationship($rows[0]));
            if ($relation instanceof BelongsToMany) {
                if ($mode !== self::RESTORABLE) {
                    $this->detach($field, $rows);
                }
            } elseif ($relation instanceof HasMany && ($field instanceof Repeater || $field->associationAttribute() !== null)) {
                $soft = $this->softDeletes($relation->getRelated());
                if ($mode === self::RESTORABLE && ! $soft) {
                    continue;
                }
                $relation->addEagerConstraints($rows);
                if ($soft && $mode !== self::RESTORABLE) {
                    $relation->withTrashed();
                }
                $children = $relation->lockForUpdate()->get()->all();
                $trashed = array_values(array_filter($children, fn (Model $child) => $this->trashed($child)));
                $active = array_values(array_filter($children, fn (Model $child) => ! $this->trashed($child)));
                if ($trashed !== []) {
                    $this->remove($trashed, $field->rowForm(), self::FORCE);
                }
                if ($active !== []) {
                    $this->remove($active, $field->rowForm(), $mode);
                }
            }
        }
        if ($mode !== self::RESTORABLE) {
            $this->delete($rows, $mode === self::FORCE);
        }
    }

    /** @param  list<Model>  $rows */
    private function delete(array $rows, bool $force): void
    {
        foreach ($rows as $row) {
            $deleted = $force && $this->softDeletes($row) ? $row->forceDelete() : $row->delete();
            if (! $deleted) {
                throw new \LogicException('The model refused removal.');
            }
        }
    }

    /**
     * Detach every pivot of the batch in one statement, keeping the relation's own pivot
     * constraints (wherePivot...), as detach() does for one parent. A custom pivot class (its model
     * events, observers or SoftDeletes) or a touching relation detaches row by row through detach().
     *
     * @param  list<Model>  $rows
     */
    private function detach(Repeater|RelationPicker $field, array $rows): void
    {
        $relation = $field->relationship($rows[0]);
        $query = $relation->newPivotQuery();
        // newPivotQuery() ends with the single-parent constraint; widen it to the whole batch.
        $parent = end($query->wheres);
        $bindings = $query->getRawBindings()['where'];
        $plain = in_array($relation->getPivotClass(), [Pivot::class, MorphPivot::class], true)
            && $relation->getRelated()->getTouchedRelations() === []
            && ! $rows[0]->touches($relation->getRelationName());
        if (! $plain || ($parent['type'] ?? null) !== 'Basic' || $parent['column'] !== $relation->getQualifiedForeignPivotKeyName() || end($bindings) !== $parent['value']) {
            foreach ($rows as $row) {
                $field->relationship($row)->detach();
            }

            return;
        }
        array_pop($query->wheres);
        array_pop($bindings);
        $query->setBindings($bindings, 'where');
        $query->whereIn($relation->getQualifiedForeignPivotKeyName(), array_map(fn (Model $row) => $row->getAttribute($relation->getParentKeyName()), $rows))->delete();
    }

    /**
     * Restoring a removed row also restores the soft-deletable nested rows its removal trashed:
     * those trashed at or after the row itself (see remove()). Rows trashed earlier on their own stay
     * trashed, and so do rows the save's own plan removes or keeps ($planned keys per relation name),
     * so a nested restorable selection that was not picked again stays removed.
     *
     * @param  array<string, list<string>>  $planned
     */
    private function restoreNested(Model $row, Form $form, string $trashedAt, array $planned = []): void
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
                if (in_array((string) $child->getKey(), $planned[$field->relationName()] ?? [], true)) {
                    continue;
                }
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
