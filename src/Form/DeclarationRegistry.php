<?php

namespace Brume\Form;

use Brume\Form\Fields\RelationPicker;
use Brume\Form\Fields\Repeater;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Declaration-only graph. Row boundaries come from fields, never guessed numeric segments. */
final class DeclarationRegistry
{
    public array $entries = [];

    public function __construct(array $fields, ?Model $model = null)
    {
        $this->walk($fields, $model);
        $this->check();
    }

    private function walk(array $fields, ?Model $model, string $base = '', ?string $row = null, array $parents = []): void
    {
        foreach ($fields as $relative => $field) {
            $path = $base.$relative;
            $this->entries[$path] = compact('field', 'model', 'row', 'parents');
            if ($field instanceof Repeater || $field instanceof RelationPicker) {
                $child = $path.($field instanceof Repeater || $field->settings()['multiple'] ? '.*' : '');
                if ($field instanceof RelationPicker && $field->relationName() === null) {
                    throw new InvalidArgumentException('Picker requires a declared relation.');
                }
                $childModel = $field->relationName() !== null && $model !== null ? $field->relationship($model)->getRelated() : $model;
                $this->walk($field->rowForm()->fields(), $childModel, $child.'.', $child, [...$parents, $path]);
            }
        }
    }

    public function resolve(ConditionPath $dependency, array $entry): string
    {
        $path = $dependency->resolve($entry['row']);
        foreach ($this->entries as $template => $target) {
            if (preg_match('~^'.str_replace('\\*', '(?:[0-9]+|\\*)', preg_quote($template, '~')).'$~D', $path)) {
                if ($target['model'] !== null && $target['field']->hasHiddenAttributes($target['model'])) {
                    throw new InvalidArgumentException('Condition depends on a hidden model attribute: '.$path);
                }

                return $template;
            }
        }
        throw new InvalidArgumentException('Unknown declaration dependency: '.$path);
    }

    private function check(): void
    {
        $edges = [];
        foreach ($this->entries as $path => $entry) {
            $field = $entry['field'];
            $edges[$path] = $entry['parents'];
            foreach (['showWhen', 'enableWhen'] as $key) {
                if (isset($field->settings()[$key])) {
                    foreach (Conditions::fromArray($field->settings()[$key])->paths() as $dependency) {
                        $edges[$path][] = $this->resolve($dependency, $entry);
                    }
                }
            }
            foreach ($field->settings()['excludeSelectedIn'] ?? [] as $dependency) {
                $this->resolve(new ConditionPath($dependency['scope'], $dependency['path']), $entry);
            }
            if (method_exists($field, 'dependencies')) {
                foreach ($field->dependencies() as $dependency) {
                    $this->resolve($dependency, $entry);
                }
            }
            if ($field instanceof Repeater) {
                if (isset($field->settings()['min'], $field->settings()['max']) && $field->settings()['min'] > $field->settings()['max']) {
                    throw new InvalidArgumentException('Repeater minimum exceeds maximum.');
                }
                foreach ($field->settings()['distinct'] ?? [] as $child) {
                    if (! isset($field->rowForm()->fields()[$child])) {
                        throw new InvalidArgumentException('Unknown distinct declaration: '.$child);
                    }
                }
            }
        }
        $visited = $visiting = [];
        $visit = function ($path) use (&$visit, &$visited, &$visiting, $edges) {
            if (isset($visiting[$path])) {
                throw new InvalidArgumentException('Activity cycle at '.$path);
            }
            if (isset($visited[$path])) {
                return;
            }
            $visiting[$path] = true;
            foreach ($edges[$path] ?? [] as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$path]);
            $visited[$path] = true;
        };
        foreach (array_keys($edges) as $path) {
            $visit($path);
        }
    }
}
