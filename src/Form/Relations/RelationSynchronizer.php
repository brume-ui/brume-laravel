<?php

namespace Brume\Form\Relations;

use Brume\Form\Fields\Repeater;
use Illuminate\Database\Eloquent\Model;
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

    private function node(array $node, ?Model $parent = null, ?array $binding = null): void
    {
        $model = $node['model'];
        foreach ($node['fields'] as $path => $field) {
            if (Arr::has($node['values'], $path)) {
                if ($field instanceof Repeater && isset($node['jsonOriginals'][$path])) {
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
        foreach ($node['relations'] as $operation) {
            if ($operation['kind'] === 'belongsTo') {
                if ($operation['row'] !== null && ($operation['row']['fields'] !== [] || $operation['row']['relations'] !== [])) {
                    $this->node($operation['row']);
                }
                $model->setAttribute($operation['foreign'], $operation['selected']);
            }
        }
        if ($node['restore']) {
            $model->setAttribute($model->getDeletedAtColumn(), null);
        }
        if (! $model->save()) {
            throw new \LogicException('The model refused form persistence.');
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
            foreach ($operation['removed'] as $removed) {
                if ($operation['kind'] === 'direct') {
                    $removed->setAttribute($operation['foreign'], null);
                    if (! $removed->save()) {
                        throw new \LogicException('The model refused deselection.');
                    }
                } elseif (! $removed->delete()) {
                    throw new \LogicException('The model refused removal.');
                }
            }
            foreach ($operation['rows'] as $row) {
                $this->node($row, $model, $row['binding']);
            }
        }
    }
}
