<?php

namespace Brume\Form\Relations;

use Brume\Form\Conditions;
use Brume\Form\DeclarationRegistry;
use Brume\Form\Fields\CustomField;
use Brume\Form\Fields\RelationPicker;
use Brume\Form\Fields\Repeater;
use Brume\Form\Form;
use Brume\Form\FormValidator;
use Brume\Form\JsonRows;
use Brume\Form\JsonValues;
use Brume\Grid\RowLookup;
use Brume\Options\OptionContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** One transaction-local preflight. No fill/save/file operations occur while constructing the graph. */
final class RelationPlanner
{
    private array $nodes = [];

    private array $entries = [];

    private array $evaluated = [];

    private array $visiting = [];

    private array $sources = [];

    private array $definitions = [];

    private array $controllers = [];

    private array $inventory = [];

    private array $evaluation = [];

    private array $shapeErrors = [];

    private ?Model $root;

    private string $connection;

    public function plan(Form $form, Model $model, array $values, ?Model $context): RelationPlan
    {
        $this->nodes = $this->entries = $this->evaluated = $this->visiting = $this->sources = $this->definitions = $this->controllers = $this->inventory = $this->evaluation = $this->shapeErrors = [];
        $this->root = $context;
        $this->connection = $model->getConnection()->getName();
        if ($model->getConnection()->transactionLevel() < 1) {
            throw new \LogicException('Relation planning requires the root transaction.');
        }
        $registry = new DeclarationRegistry($form->fields(), $model);
        foreach ($registry->entries as $entry) {
            foreach (['showWhen', 'enableWhen'] as $condition) {
                foreach (isset($entry['field']->settings()[$condition]) ? Conditions::fromArray($entry['field']->settings()[$condition])->paths() : [] as $dependency) {
                    $this->controllers[$registry->resolve($dependency, $entry)] = true;
                }
            }
        }
        $this->prepare($form, $model, $context, $values, null, '', '', [], [], null);
        foreach ($form->fields() as $path => $field) {
            Arr::set($this->evaluation, $path, $this->value($path));
        }
        $this->inventory['original'] = [];
        $original = $context === null ? null : clone $context;
        if ($original !== null) {
            $original->setRawAttributes($context->getRawOriginal(), true);
        }
        $this->original($form, $original, null, '', '', []);
        foreach ($this->nodes as $node) {
            if ($node['storageResolved'] ?? false) {
                $stored = clone $node['model'];
                $stored->setRawAttributes($stored->getRawOriginal(), true);
                $this->original($node['form'], $stored, null, $node['prefix'], $node['template'], $node['storageContext']);
            }
        }
        $validated = $this->validateNode('');
        // Membership applies only to fields that survive final activity/allowlist validation.
        $tree = $this->operations('', $validated);

        return new RelationPlan($tree, $validated, $this->inventory);
    }

    /** Locked raw storage inventory includes removed and omitted descendants, before any hook or copy. */
    private function original(Form $form, ?Model $owner, ?array $json, string $prefix, string $template, array $context): void
    {
        foreach ($form->fields() as $relative => $field) {
            $path = $prefix.$relative;
            $relational = ($field instanceof Repeater || $field instanceof RelationPicker) && $field->relationName() !== null;
            $stored = $relational ? null : ($json !== null ? data_get($json, $field->attributeName()) : ($owner === null ? null : JsonValues::storedValue($owner, $field->attributeName(), data_get($owner, $field->attributeName()))));
            $this->inventory['original'][] = ['field' => $field, 'path' => $path, 'template' => $template.$relative, 'rowContext' => $context, 'owner' => $owner === null ? null : clone $owner, 'stored' => $stored, 'rawAttributes' => $owner?->getAttributes() ?? []];
            if (! $field instanceof Repeater && ! $field instanceof RelationPicker) {
                continue;
            }
            $relational = $field->relationName() !== null;
            if ($relational) {
                if ($owner === null || ! $owner->exists) {
                    continue;
                }
                $relation = $field->relationship($owner);
                $this->sameConnection($relation->getRelated());
                if ($field instanceof RelationPicker && $field->restoresRemoved()) {
                    $relation->withTrashed();
                }
                $rows = $relation->lockForUpdate()->get()->all();
            } else {
                $rows = JsonRows::hydrate($stored ?? [], $this->root, $template.$relative, $context);
            }
            $multiple = $field instanceof Repeater || $field->settings()['multiple'];
            foreach ($rows as $index => $row) {
                if ($field instanceof RelationPicker) {
                    $id = (string) ($field->associationAttribute() !== null ? $row->getAttribute($field->associationAttribute()) : $row->getKey());
                    $identity = ['key' => $id, 'id' => $id];
                } else {
                    $identity = $relational ? ['key' => 'record:'.$row->getKey(), 'id' => (string) $row->getKey()] : $row['_brume'];
                }
                $childOwner = $relational ? ($relation instanceof BelongsToMany ? $row->getRelation($relation->getPivotAccessor()) : $row) : $owner;
                $this->original($field->rowForm(), $childOwner, $relational ? null : $row, $path.($multiple ? '.'.$index : '').'.', $template.$relative.($multiple ? '.*.' : '.'), [...$context, ['path' => $template.$relative] + $identity]);
            }
        }
    }

    private function sameConnection(Model $model): void
    {
        if ($model->getConnection()->getName() !== $this->connection) {
            throw ValidationException::withMessages(['relations' => 'Mixed model connections are not supported.']);
        }
    }

    private function prepare(Form $form, Model $model, ?Model $callback, array $input, ?array $json, string $prefix, string $template, array $context, array $storageContext, ?string $parent): void
    {
        $this->sameConnection($model);
        $this->nodes[$prefix] = compact('form', 'model', 'callback', 'input', 'json', 'prefix', 'template', 'context', 'storageContext', 'parent');
        // First register siblings; source dependency resolution must not depend on declaration order.
        foreach ($form->fields() as $relative => $field) {
            $path = $prefix.$relative;
            $stored = $json !== null ? $field->valueFromJson($json) : (($field instanceof Repeater || $field instanceof RelationPicker) ? null : $field->valueForContext($model->exists ? $model : null, $callback));
            $this->entries[$path] = ['field' => $field, 'node' => $prefix, 'relative' => $relative, 'stored' => $stored, 'children' => [], 'existing' => [], 'relation' => null];
            $this->definitions[$path] = ['type' => $field->type()] + $field->settings();
            $this->inventory['fields'][$path] = ['field' => $field, 'path' => $path, 'template' => $template.$relative, 'rowContext' => $context, 'storageContext' => $storageContext, 'callback' => $callback, 'json' => $json !== null, 'model' => clone $model, 'owner' => $model->exists ? clone $model : null, 'stored' => $stored, 'submitted' => Arr::has($input, $relative), 'validated' => false];
        }
        foreach ($form->fields() as $relative => $field) {
            if ($field instanceof Repeater || $field instanceof RelationPicker) {
                $this->prepareCollection($prefix, $relative);
            }
        }
    }

    private function prepareCollection(string $prefix, string $relative, bool $storedOnly = false): void
    {
        extract($this->nodes[$prefix]);
        $field = $form->fields()[$relative];
        $path = $prefix.$relative;
        $isRelation = $field->relationName() !== null;
        $relation = $isRelation ? $field->relationship($model) : null;
        if ($relation !== null) {
            $this->sameConnection($relation->getRelated());
        }
        $existing = [];
        if ($isRelation && $model->exists) {
            // Relation constraints always use immutable persisted keys, never hook-dirty identity attributes.
            $owner = clone $model;
            foreach ([$owner->getKeyName(), ...array_keys($owner->getDirty())] as $attribute) {
                if (array_key_exists($attribute, $owner->getRawOriginal())) {
                    $owner->setAttribute($attribute, $owner->getRawOriginal($attribute));
                }
            }
            $read = $field->relationship($owner);
            if ($field instanceof RelationPicker && $field->restoresRemoved()) {
                $read->withTrashed();
            }
            $existing = $read->lockForUpdate()->get()->all();
        }
        $this->entries[$path]['relation'] = $relation;
        $this->entries[$path]['existing'] = $existing;
        $storedRows = [];
        if (! $isRelation) {
            $storedRows = $json !== null ? data_get($json, $field->attributeName(), []) : ($model->exists ? $field->storedJsonRowsForContext($model, $callback) : []);
            $this->entries[$path]['stored'] = $storedRows;
            $storedRows = JsonRows::hydrate($storedRows ?? [], $this->root, $template.$relative, $storageContext);
        }
        $submitted = ! $storedOnly && Arr::has($input, $relative);
        // Readonly containers never use request identities. Scalar callbacks below verify picker owners first.
        $multiple = $field instanceof Repeater || $field->settings()['multiple'];
        if ($isRelation && ! $submitted) {
            // Use only identities from the locked rows. Descendant values are resolved from
            // their own locked models, never a second unlocked snapshot of the tree.
            $authoritativeRows = [];
            foreach ($existing as $item) {
                if (method_exists($item, 'trashed') && $item->trashed()) {
                    continue;
                }
                $authoritativeRows[] = $field instanceof RelationPicker
                    ? ['id' => (string) ($field->associationAttribute() !== null ? $item->getAttribute($field->associationAttribute()) : $item->getKey())]
                    : ['_brume' => ['key' => 'record:'.$item->getKey(), 'id' => (string) $item->getKey()]];
            }
            $raw = $multiple ? $authoritativeRows : ($authoritativeRows[0] ?? null);
        } else {
            $raw = $submitted ? Arr::get($input, $relative) : $storedRows;
        }
        $rows = $multiple ? ($raw ?? []) : ($raw === null ? [] : [$raw]);
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->shapeErrors[$path] = $path;

            return;
        }
        $seenKeys = $seenIds = [];
        foreach ($rows as $index => $rawRow) {
            $row = $rawRow instanceof \stdClass ? get_object_vars($rawRow) : $rawRow;
            if (! is_array($row) || array_is_list($row)) {
                $this->shapeErrors[$path] = $path.'.'.$index;

                continue;
            }
            $rowPath = $multiple ? $path.'.'.$index : $path;
            $childPrefix = $rowPath.'.';
            $childTemplate = $template.$relative.($multiple ? '.*.' : '.');
            $matched = null;
            $selected = null;
            if ($field instanceof RelationPicker) {
                $id = $row['id'] ?? null;
                if (! is_string($id) || $id === '' || isset($seenIds[$id])) {
                    $this->shapeErrors[$path] = $rowPath;

                    continue;
                }
                $identity = ['key' => $id, 'id' => $id];
                $selected = $id;
                $attribute = $field->associationAttribute() ?? $relation->getRelated()->getKeyName();
                $matches = array_values(array_filter($existing, fn ($item) => (string) $item->getAttribute($attribute) === $id));
                if (count($matches) > 1) {
                    $this->shapeErrors[$path] = $rowPath;

                    continue;
                }
                $matched = $matches[0] ?? null;
                $childModel = $relation instanceof BelongsToMany ? ($matched?->getRelation($relation->getPivotAccessor()) ?? $relation->newPivot([], false)) : ($matched ?? $relation->getRelated()->newInstance());
                $childCallback = $matched === null ? null : $childModel;
                $metadata = ['id' => $id];
                unset($row['id']);
            } else {
                $identity = $row['_brume'] ?? null;
                if ($identity instanceof \stdClass) {
                    $identity = get_object_vars($identity);
                }
                if (! is_array($identity) || array_diff(array_keys($identity), $isRelation ? ['key', 'id'] : ['key']) !== [] || ! is_string($identity['key'] ?? null) || $identity['key'] === '' || strlen($identity['key']) > 512) {
                    $this->shapeErrors[$path] = $rowPath;

                    continue;
                }
                if (isset($identity['id'])) {
                    $lookup = new RowLookup($relation->getQuery());
                    $id = $lookup->normalizeId($identity['id']);
                    if (! is_string($identity['id']) || $id === null || (string) $id !== $identity['id']) {
                        $this->shapeErrors[$path] = $rowPath;

                        continue;
                    }
                    $matched = collect($existing)->first(fn ($item) => (string) $item->getKey() === $identity['id']);
                    if ($matched === null) {
                        $this->shapeErrors[$path] = $rowPath;

                        continue;
                    }
                }
                $childModel = $isRelation ? ($matched ?? $relation->getRelated()->newInstance()) : $model;
                $childCallback = $isRelation ? $matched : $this->root;
                $metadata = ['_brume' => $identity];
                unset($row['_brume']);
            }
            if (isset($seenKeys[$identity['key']]) || (isset($identity['id']) && isset($seenIds[$identity['id']]))) {
                $this->shapeErrors[$path] = $rowPath;

                continue;
            }
            $seenKeys[$identity['key']] = true;
            if (isset($identity['id'])) {
                $seenIds[$identity['id']] = true;
            }
            $childJson = ! $isRelation ? (collect($storedRows)->first(fn ($item) => $item['_brume']['key'] === $identity['key']) ?? []) : null;
            $childContext = [...$context, ['path' => $template.$relative] + $identity];
            $storageIdentity = $isRelation && $field instanceof Repeater && $matched !== null ? ['key' => 'record:'.$matched->getKey(), 'id' => (string) $matched->getKey()] : $identity;
            $this->entries[$path]['children'][] = $childPrefix;
            $this->prepare($field->rowForm(), $childModel, $childCallback, $row, $childJson, $childPrefix, $childTemplate, $childContext, [...$storageContext, ['path' => $template.$relative] + $storageIdentity], $path);
            $this->nodes[$childPrefix] += compact('metadata', 'selected', 'matched');
            $this->inventory['rows'][$childPrefix] = ['path' => $rowPath, 'rowContext' => $childContext, 'owner' => $childModel->exists ? clone $childModel : null];
        }
    }

    private function owner(string $prefix): void
    {
        $node = $this->nodes[$prefix];
        $parent = $node['parent'];
        if ($parent === null) {
            return;
        }
        $entry = $this->entries[$parent];
        $this->owner($entry['node']);
        if ($entry['field'] instanceof RelationPicker) {
            $query = $this->source($parent);
            $id = (new RowLookup($query))->normalizeId($node['selected']);
            if ($id === null || (string) $id !== $node['selected']) {
                $this->invalid($parent);
            }
            if ($node['matched'] === null) {
                $selected = (new RowLookup($query->lockForUpdate()))->find([$id])?->first();
                if ($selected === null || (string) $selected->getKey() !== $node['selected']) {
                    $this->invalid($parent);
                }
                $relation = $entry['relation'];
                if (! ($node['storageResolved'] ?? false) && ($relation instanceof BelongsTo || ($relation instanceof HasMany && $entry['field']->associationAttribute() === null))) {
                    // Existing source storage is distinct from a new association's null evaluation context.
                    $this->sameConnection($selected);
                    $this->forgetDescendants($prefix);
                    $this->prepare($node['form'], $selected, null, $node['input'], $node['json'], $prefix, $node['template'], $node['context'], $node['storageContext'], $parent);
                    $this->nodes[$prefix] += ['metadata' => $node['metadata'], 'selected' => $node['selected'], 'matched' => null, 'storageResolved' => true];
                    $this->inventory['rows'][$prefix]['owner'] = clone $selected;
                }
            }
        }
    }

    private function forgetDescendants(string $prefix): void
    {
        foreach (array_keys($this->entries) as $path) {
            if (str_starts_with($path, $prefix)) {
                unset($this->entries[$path], $this->definitions[$path], $this->evaluated[$path], $this->shapeErrors[$path], $this->sources[$path], $this->inventory['fields'][$path]);
            }
        }
        foreach (array_keys($this->nodes) as $path) {
            if ($path !== $prefix && str_starts_with($path, $prefix)) {
                unset($this->nodes[$path], $this->inventory['rows'][$path]);
            }
        }
    }

    /** A numeric dependency can reach descendants not prepared until their selected owner is resolved. */
    private function resolveStoragePath(string $path): void
    {
        $resolved = [];
        do {
            $pending = array_values(array_filter(array_keys($this->nodes), fn ($prefix) => $prefix !== '' && str_starts_with($path.'.', $prefix) && ! isset($resolved[$prefix])));
            usort($pending, fn ($a, $b) => strlen($a) <=> strlen($b));
            foreach ($pending as $prefix) {
                if (isset($this->nodes[$prefix])) {
                    $this->owner($prefix);
                }
                $resolved[$prefix] = true;
            }
        } while ($pending !== []);
    }

    private function locked(string $prefix): bool
    {
        $parent = $this->nodes[$prefix]['parent'];
        if ($parent === null) {
            return false;
        }
        $entry = $this->entries[$parent];
        $node = $this->nodes[$entry['node']];
        $this->owner($entry['node']);

        return $this->locked($entry['node']) || ! $entry['field']->isEditable($node['callback']);
    }

    private function active(string $path): bool
    {
        $entry = $this->entries[$path];
        $node = $this->nodes[$entry['node']];
        $rowPath = $entry['node'] === '' ? null : rtrim($entry['node'], '.');
        foreach (['showWhen', 'enableWhen'] as $name) {
            if (! isset($entry['field']->settings()[$name])) {
                continue;
            }
            $condition = Conditions::fromArray($entry['field']->settings()[$name]);
            $inputs = [];
            $invalid = [];
            foreach ($condition->paths() as $dependency) {
                $target = $dependency->resolve($rowPath);
                $this->resolveStoragePath($target);
                $pattern = '~^'.str_replace('\\*', '[0-9]+', preg_quote($target, '~')).'$~D';
                foreach (array_keys($this->entries) as $candidate) {
                    if (preg_match($pattern, $candidate)) {
                        $value = $this->value($candidate);
                        Arr::set($inputs, $candidate, $value);
                        if (! Conditions::canonical($this->definitions[$candidate], $value)[0]) {
                            $invalid[] = $candidate;
                        }
                    }
                }
            }
            if (! $condition->evaluate($inputs, $rowPath, $this->definitions, $invalid)) {
                return false;
            }
        }

        return true;
    }

    private function value(string $path): mixed
    {
        if (array_key_exists($path, $this->evaluated)) {
            return $this->evaluated[$path];
        }
        if (isset($this->visiting['value:'.$path])) {
            $this->invalid($path, 'Cyclic source dependencies.');
        }
        $entry = $this->entries[$path] ?? null;
        if ($entry === null) {
            return null;
        }
        $this->visiting['value:'.$path] = true;
        $node = $this->nodes[$entry['node']];
        $field = $entry['field'];
        $this->owner($entry['node']);
        $entry = $this->entries[$path];
        $node = $this->nodes[$entry['node']];
        $readonly = $this->locked($entry['node']) || ! $field->isEditable($node['callback']);
        if ($field instanceof Repeater || $field instanceof RelationPicker) {
            if (($readonly || ! $this->active($path)) && ! ($entry['authoritative'] ?? false)) {
                foreach (array_keys($this->entries) as $descendant) {
                    if (str_starts_with($descendant, $path.'.')) {
                        unset($this->entries[$descendant], $this->definitions[$descendant], $this->evaluated[$descendant], $this->inventory['fields'][$descendant]);
                    }
                }
                foreach (array_keys($this->nodes) as $descendant) {
                    if (str_starts_with($descendant, $path.'.')) {
                        unset($this->nodes[$descendant]);
                    }
                }
                unset($this->shapeErrors[$path]);
                $this->entries[$path]['children'] = [];
                $this->prepareCollection($entry['node'], $entry['relative'], true);
                $this->entries[$path]['authoritative'] = true;
                $entry = $this->entries[$path];
            }
            $rows = [];
            foreach ($entry['children'] as $child) {
                $this->owner($child);
                $row = $this->nodes[$child]['metadata'];
                foreach ($field->rowForm()->fields() as $relative => $unused) {
                    Arr::set($row, $relative, $this->value($child.$relative));
                }
                $rows[] = $row;
            }
            $value = $field instanceof RelationPicker && ! $field->settings()['multiple'] ? ($rows[0] ?? null) : $rows;
        } else {
            $value = $readonly || ! $this->active($path) || ! Arr::has($node['input'], $entry['relative']) ? $entry['stored'] : Arr::get($node['input'], $entry['relative']);
        }
        unset($this->visiting['value:'.$path]);

        return $this->evaluated[$path] = $value;
    }

    private function source(string $path)
    {
        if (isset($this->sources[$path])) {
            return clone $this->sources[$path];
        }
        if (isset($this->visiting['source:'.$path])) {
            $this->invalid($path, 'Cyclic source dependencies.');
        }
        $this->visiting['source:'.$path] = true;
        $entry = $this->entries[$path];
        $node = $this->nodes[$entry['node']];
        $dependencies = [];
        foreach ($entry['field']->dependencies() as $name => $dependency) {
            $target = $dependency->resolve($entry['node'] === '' ? null : rtrim($entry['node'], '.'));
            $dependencies[$name] = $this->dependency($target);
        }
        $query = $entry['field']->optionSource()->query(new OptionContext($this->root, $dependencies, $node['context']));
        $this->sameConnection($query->getModel());
        unset($this->visiting['source:'.$path]);
        $this->sources[$path] = $query;

        return clone $query;
    }

    private function dependency(string $path): mixed
    {
        $this->resolveStoragePath($path);
        $entry = $this->entries[$path] ?? null;
        if ($entry === null) {
            return null;
        }
        $field = $entry['field'];
        $node = $this->nodes[$entry['node']];
        $value = $this->value($path);
        $entry = $this->entries[$path];
        $node = $this->nodes[$entry['node']];
        if (isset($this->shapeErrors[$path]) && $this->active($path)) {
            $this->invalid($this->shapeErrors[$path]);
        }
        [$valid, $canonical] = Conditions::canonical($this->definitions[$path], $value);
        if (! $valid) {
            $this->invalid($path);
        }
        $rules = $field->serverRules($node['callback'], $node['model']);
        $rules = ['value' => $rules];
        if ($field instanceof CustomField) {
            foreach ($field->childValidationRules() as $child => $childRules) {
                $rules['value.'.$child] = $childRules;
            }
        }
        if (in_array($field->type(), ['multipleSelect', 'tags', 'checkbox'], true)) {
            $rules['value.*'] = ['string'];
            if (isset($field->settings()['options'])) {
                $rules['value.*'][] = $field->choiceRule();
            }
        }
        $validator = Validator::make(['value' => JsonValues::validationView($value)], $rules);
        if ($validator->fails()) {
            $this->invalid($path);
        }
        if ($field instanceof Repeater || $field instanceof RelationPicker) {
            $rows = [];
            foreach ($entry['children'] as $child) {
                $this->owner($child);
                $row = $this->nodes[$child]['metadata'];
                foreach ($field->rowForm()->fields() as $relative => $unused) {
                    Arr::set($row, $relative, $this->dependency($child.$relative));
                }
                $rows[] = $row;
            }

            return $field instanceof RelationPicker && ! $field->settings()['multiple'] ? ($rows[0] ?? null) : $rows;
        }

        return $canonical;
    }

    private function validateNode(string $prefix): array
    {
        $node = $this->nodes[$prefix];
        try {
            return FormValidator::validateFields($node['form']->fields(), $node['input'], $node['callback'], $node['model'], $this->evaluation, $prefix === '' ? null : rtrim($prefix, '.'), $node['json'], $node['storageContext'], $node['template'], $this->definitions, [], $this->controllers,
                function ($relative, $field, $input) use ($prefix) {
                    $entry = $this->entries[$prefix.$relative];
                    $rows = [];
                    if (isset($this->shapeErrors[$prefix.$relative])) {
                        $this->invalid($this->shapeErrors[$prefix.$relative]);
                    }
                    foreach ($entry['children'] as $child) {
                        $rows[] = $this->nodes[$child]['metadata'] + $this->validateNode($child);
                    }
                    foreach ($field->settings()['distinct'] ?? [] as $distinct) {
                        $seen = [];
                        foreach ($rows as $index => $row) {
                            if (! Arr::has($row, $distinct) || Arr::get($row, $distinct) === null || Arr::get($row, $distinct) === '') {
                                continue;
                            }
                            $childField = $field->rowForm()->fields()[$distinct];
                            $key = json_encode(Conditions::canonical(['type' => $childField->type()] + $childField->settings(), Arr::get($row, $distinct))[1]);
                            if (isset($seen[$key])) {
                                $this->invalid($prefix.$relative.'.'.$index.'.'.$distinct);
                            }
                            $seen[$key] = true;
                        }
                    }

                    return $field instanceof RelationPicker && ! $field->settings()['multiple'] ? ($rows[0] ?? null) : $rows;
                });
        } catch (ValidationException $e) {
            if ($prefix === '') {
                throw $e;
            }
            $errors = [];
            foreach ($e->errors() as $path => $messages) {
                $errors[str_starts_with($path, $prefix) ? $path : $prefix.$path] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    private function eligible(string $path, array $ids): array
    {
        $query = $this->source($path)->lockForUpdate();
        $lookup = new RowLookup($query);
        $models = [];
        foreach ($ids as $id) {
            $canonical = $lookup->normalizeId($id);
            if (! is_string($id) || $canonical === null || (string) $canonical !== $id || isset($models[$id])) {
                $this->invalid($path);
            }
            $found = $lookup->find([$canonical]);
            if ($found === null || $found->count() !== 1 || (string) $found->first()->getKey() !== $id) {
                $this->invalid($path, 'Selection is not currently eligible.');
            }
            $models[$id] = $found->first();
        }

        return $models;
    }

    private function operations(string $prefix, array $values): array
    {
        $node = $this->nodes[$prefix];
        $fields = $relations = [];
        foreach ($node['form']->fields() as $relative => $field) {
            if (! Arr::has($values, $relative)) {
                continue;
            }
            $path = $prefix.$relative;
            $value = Arr::get($values, $relative);
            $entry = $this->entries[$path];
            $this->inventory['fields'][$path] += ['value' => $value, 'active' => $field->isEditable($node['callback']) && ! $this->locked($prefix) && $this->active($path)];
            $this->inventory['fields'][$path]['validated'] = true;
            if (method_exists($field, 'optionSource') && $field->optionSource() !== null && $field->type() !== 'tags' && $field->isEditable($node['callback']) && ! $this->locked($prefix) && $this->active($path)) {
                $ids = $field instanceof RelationPicker ? array_map(fn ($child) => $this->nodes[$child]['selected'], $entry['children']) : ($field->type() === 'multipleSelect' ? ($value ?? []) : ($value === null || $value === '' ? [] : [$value]));
                $selectedModels = $this->eligible($path, $ids);
            }
            if (! $field instanceof RelationPicker && (! $field instanceof Repeater || $field->relationName() === null)) {
                $fields[$relative] = $field;
                // Nested JSON remote fields still require eligibility; JSON is persisted by its declared writer.
                if ($field instanceof Repeater) {
                    foreach ($entry['children'] as $index => $child) {
                        if (is_array($value[$index] ?? null)) {
                            $value[$index] = $this->operations($child, $value[$index])['values'];
                        }
                    }
                    Arr::set($values, $relative, $value);
                }

                continue;
            }
            $relation = $entry['relation'];
            $name = $field->relationName();
            if ($relation instanceof BelongsTo) {
                $selected = $entry['children'] === [] ? null : $selectedModels[$this->nodes[$entry['children'][0]]['selected']]->getAttribute($relation->getOwnerKeyName());
                if ($selected === null) {
                    $column = collect($node['model']->getConnection()->getSchemaBuilder()->getColumns($node['model']->getTable()))->firstWhere('name', $relation->getForeignKeyName());
                    if (! ($column['nullable'] ?? false)) {
                        $this->invalid($path);
                    }
                }
                $childOperation = null;
                if ($entry['children'] !== []) {
                    $child = $entry['children'][0];
                    $childOperation = $this->operations($child, $value);
                }
                $relations[] = ['kind' => 'belongsTo', 'foreign' => $relation->getForeignKeyName(), 'selected' => $selected, 'row' => $childOperation];

                continue;
            }
            $kind = $relation instanceof BelongsToMany ? 'pivot' : ($field instanceof RelationPicker && $field->associationAttribute() === null ? 'direct' : 'children');
            $rows = $kept = [];
            foreach ($entry['children'] as $index => $child) {
                $childNode = $this->nodes[$child];
                $rowValue = $field instanceof RelationPicker && ! $field->settings()['multiple'] ? $value : $value[$index];
                $operation = $this->operations($child, $rowValue);
                $operation['selected'] = $childNode['selected'];
                if ($kind === 'pivot') {
                    $operation['relatedKey'] = $selectedModels[$childNode['selected']]->getAttribute($relation->getRelatedKeyName());
                }
                $operation['restore'] = $field instanceof RelationPicker && $field->restoresRemoved() && $childNode['matched'] !== null && $childNode['matched']->trashed();
                if ($kind !== 'pivot') {
                    $operation['binding'] = ['foreign' => $relation->getForeignKeyName(), 'local' => $relation->getLocalKeyName()];
                    if ($field instanceof RelationPicker && $field->associationAttribute() !== null) {
                        $operation['binding'] += ['association' => $field->associationAttribute(), 'selected' => $childNode['selected']];
                    }
                    if ($field instanceof Repeater && $field->orderAttribute() !== null) {
                        $operation['binding'] += ['order' => $field->orderAttribute(), 'position' => $index];
                    }
                }
                $rows[] = $operation;
                if ($childNode['matched'] !== null) {
                    $kept[] = (string) $childNode['matched']->getKey();
                }
            }
            $removed = array_values(array_filter($entry['existing'], fn ($existing) => ! in_array((string) $existing->getKey(), $kept, true)));
            foreach ($removed as $removedModel) {
                $this->inventory['removed'][] = ['path' => $path, 'template' => $node['template'].$relative, 'rowContext' => $node['storageContext'], 'kind' => $kind, 'identity' => $field instanceof RelationPicker ? (string) ($field->associationAttribute() !== null ? $removedModel->getAttribute($field->associationAttribute()) : $removedModel->getKey()) : (string) $removedModel->getKey(), 'owner' => clone $removedModel, 'form' => $field->rowForm()];
            }
            $relations[] = ['kind' => $kind, 'name' => $name, 'rows' => $rows, 'removed' => $kind === 'pivot' ? array_map(fn ($row) => $row->getRelation($relation->getPivotAccessor())->getAttribute($relation->getRelatedPivotKeyName()), $removed) : $removed, 'foreign' => $kind !== 'pivot' ? $relation->getForeignKeyName() : null];
        }

        return ['prefix' => $prefix, 'model' => $node['model'], 'fields' => $fields, 'values' => $values, 'relations' => $relations, 'restore' => false];
    }

    private function invalid(string $path, string $message = 'Invalid relation graph.'): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }
}
