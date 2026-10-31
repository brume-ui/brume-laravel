<?php

namespace Brume\Options;

use Brume\Form\Conditions;
use Brume\Form\DeclarationRegistry;
use Brume\Form\Fields\CustomField;
use Brume\Form\Fields\RelationPicker;
use Brume\Form\Fields\Repeater;
use Brume\Form\Form;
use Brume\Form\FormSnapshot;
use Brume\Form\JsonRows;
use Brume\Form\JsonValues;
use Brume\Grid\RowLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Operation-local read-only authority graph; no metadata is an authorization token. */
final class FormOptionContext
{
    private array $ancestors = [];

    private array $visiting = [];

    private array $resolved = [];

    public function __construct(private Form $form, private DeclarationRegistry $registry, mixed $ancestors)
    {
        if (! is_array($ancestors) || ! array_is_list($ancestors) || count($ancestors) > 100) {
            $this->invalid('ancestorSources');
        }
        foreach ($ancestors as $ancestor) {
            if (! $ancestor instanceof stdClass || array_diff(array_keys(get_object_vars($ancestor)), ['path', 'rowContext', 'depends', 'dependencyContexts']) !== [] || ! is_string($ancestor->path ?? null) || ! ($registry->entries[$ancestor->path]['field'] ?? null) instanceof RelationPicker || ! ($ancestor->depends ?? null) instanceof stdClass) {
                $this->invalid('ancestorSources');
            }
            $chain = $this->chain($ancestor->rowContext ?? null);
            $key = $this->key($ancestor->path, $chain);
            if (isset($this->ancestors[$key])) {
                $this->invalid('ancestorSources');
            }
            $this->ancestors[$key] = [$ancestor->path, $chain, $ancestor->depends, $ancestor->dependencyContexts ?? new stdClass];
        }
    }

    public function resolve(array $entry, array $owner, mixed $depends, mixed $contexts): OptionContext
    {
        $this->row($this->form, $this->registry, $entry, $owner);
        if (! $depends instanceof stdClass || ! $contexts instanceof stdClass) {
            $this->invalid('depends');
        }
        $declarations = $entry['field']->dependencies();
        if (array_diff(array_keys(get_object_vars($depends)), array_keys($declarations)) !== [] || array_diff(array_keys(get_object_vars($contexts)), array_keys($declarations)) !== []) {
            $this->invalid('depends');
        }
        $values = [];
        foreach ($declarations as $name => $dependency) {
            $targetPath = $this->registry->resolve($dependency, $entry);
            $target = $this->registry->entries[$targetPath];
            $chain = property_exists($contexts, $name) ? $this->chain($contexts->{$name}) : null;
            if ($chain === null) {
                // Only row-relative ancestors/current row are derivable. A numeric root path
                // follows draft position and needs its own stable identity, even at index zero.
                $chain = $target['parents'] === [] ? [] : ($dependency->scope === 'row' && array_slice($entry['parents'], 0, count($target['parents'])) === $target['parents'] ? array_slice($owner, 0, count($target['parents'])) : null);
            }
            if ($dependency->scope === 'row' && (! is_array($chain) || array_slice($chain, 0, count($owner)) !== $owner)) {
                $this->invalid('dependencyContexts.'.$name);
            }
            $values[$name] = $this->value($targetPath, $chain, $depends->{$name} ?? null, 'depends.'.$name);
        }

        return new OptionContext($this->form->record(), $values, $owner);
    }

    private function value(string $path, mixed $chain, mixed $submitted, string $error): mixed
    {
        $entry = $this->registry->entries[$path];
        [$record, $json, $locked, $storage] = $this->row($this->form, $this->registry, $entry, $chain);
        $field = $entry['field'];
        if ($entry['model'] !== null && $field->hasHiddenAttributes($entry['model'])) {
            throw new \InvalidArgumentException('Option dependency uses a hidden attribute: '.$path);
        }
        $locked = $locked || ! $field->isEditable($record);
        $collection = $field instanceof Repeater || $field instanceof RelationPicker;
        $value = $locked
            ? ($collection ? (new FormSnapshot)->value($field, $record, $json, $chain, $path, $this->form->record(), $storage) : ($json !== null ? $field->valueFromJson($json) : $field->valueForContext($storage, $record)))
            : $submitted;
        [$valid, $canonical] = Conditions::canonical(['type' => $field->type()] + $field->settings(), $value);
        if (! $valid) {
            $this->invalid($error);
        }
        $rules = ['value' => $field->serverRules($record, $entry['model'])];
        if ($field instanceof CustomField) {
            foreach ($field->childValidationRules() as $child => $rule) {
                $rules['value.'.$child] = $rule;
            }
        }
        if (in_array($field->type(), ['multipleSelect', 'tags', 'checkbox'], true)) {
            $rules['value.*'] = ['string'];
            if (isset($field->settings()['options'])) {
                $rules['value.*'][] = $field->choiceRule();
            }
        }
        // Remote choices have no static choice allowlist; their source eligibility belongs to
        // the dependent source declaration and, for writes, the relation planner.
        $validator = Validator::make(['value' => JsonValues::validationView($value)], $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages([$error => $validator->errors()->all()]);
        }
        if (! $collection || $value === null || $value === '') {
            return $canonical;
        }
        $multiple = $field instanceof Repeater || $field->settings()['multiple'];
        $rows = $multiple ? $value : [$value];
        $result = $seen = [];
        foreach ($rows as $index => $row) {
            $row = $row instanceof stdClass ? get_object_vars($row) : $row;
            if ($field instanceof RelationPicker) {
                $identity = ['path' => $path, 'key' => $row['id'], 'id' => $row['id']];
                $output = ['id' => $row['id']];
            } else {
                $metadata = $row['_brume'] ?? null;
                $metadata = $metadata instanceof stdClass ? get_object_vars($metadata) : $metadata;
                if (! is_array($metadata) || array_diff(array_keys($metadata), ['key', 'id']) !== [] || ! is_string($metadata['key'] ?? null)) {
                    $this->invalid($error);
                }
                $identity = ['path' => $path] + $metadata;
                $output = ['_brume' => $metadata];
            }
            if (isset($seen[$identity['key']])) {
                $this->invalid($error);
            }
            $seen[$identity['key']] = true;
            $childChain = [...$chain, $identity];
            $this->row($this->form, $this->registry, ['parents' => [...$entry['parents'], $path]], $childChain);
            foreach ($field->rowForm()->fields() as $relative => $child) {
                $childPath = $path.($multiple ? '.*.' : '.').$relative;
                data_set($output, $relative, $this->value($childPath, $childChain, data_get($row, $relative), $error.'.'.$index.'.'.$relative));
            }
            $result[] = $output;
        }

        return $multiple ? $result : ($result[0] ?? null);
    }

    public function assertConsumed(): void
    {
        if (array_diff_key($this->ancestors, $this->resolved) !== []) {
            $this->invalid('ancestorSources');
        }
    }

    private function key(string $path, array $chain): string
    {
        return json_encode([$path, $chain], JSON_THROW_ON_ERROR);
    }

    private function ancestor(string $path, array $chain): OptionContext
    {
        $key = $this->key($path, $chain);
        if (isset($this->visiting[$key])) {
            $this->invalid('ancestorSources');
        }
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }
        $entry = $this->registry->entries[$path];
        $metadata = $this->ancestors[$key] ?? [$path, $chain, new stdClass, new stdClass];
        $this->visiting[$key] = true;
        $context = $this->resolve($entry, $chain, $metadata[2], $metadata[3]);
        unset($this->visiting[$key]);

        return $this->resolved[$key] = $context;
    }

    /** Snapshot only declared fields, using the exact scoped storage/callback split of reads. */
    public function pickerAuthority(array $entry, array $owner, string $id, OptionContext $context): array
    {
        $path = $entry['path'];
        $this->resolved[$this->key($path, $owner)] = $context;
        $chain = [...$owner, ['path' => $path, 'key' => $id, 'id' => $id]];
        [$record, $json, $locked, $storage] = $this->row($this->form, $this->registry, ['parents' => [...$entry['parents'], $path]], $chain);
        $snapshot = new FormSnapshot;
        $multiple = $entry['field']->settings()['multiple'];
        $values = $snapshot->values($entry['field']->rowForm(), $record, $json, $chain, $path.($multiple ? '.*.' : '.'), $this->form->record(), $storage);

        return ['values' => $values === [] ? (object) [] : $values]
            + ($snapshot->rowFields !== [] ? ['rowFields' => $snapshot->rowFields] : []);
    }

    /** Internal upload authorization uses the same declared ancestor authority as options. */
    public function uploadContext(array $entry, array $chain): array
    {
        return $this->row($this->form, $this->registry, $entry, $chain);
    }

    /** @return array{?Model, ?array, bool, ?Model} */
    private function row(Form $form, DeclarationRegistry $registry, array $entry, mixed $chain): array
    {
        if (! is_array($chain) || ! array_is_list($chain) || count($chain) !== count($entry['parents'])) {
            $this->invalid('rowContext');
        }
        $storage = $record = $root = $form->record();
        $json = null;
        $locked = false;
        $ancestors = $storageAncestors = [];
        foreach ($entry['parents'] as $index => $path) {
            $identity = $chain[$index];
            if ($identity instanceof stdClass) {
                $identity = get_object_vars($identity);
            }
            if (! is_array($identity) || array_diff(array_keys($identity), ['path', 'key', 'id']) !== [] || ($identity['path'] ?? null) !== $path || ! is_string($identity['key'] ?? null) || $identity['key'] === '' || strlen($identity['key']) > 512 || (isset($identity['id']) && (! is_string($identity['id']) || $identity['id'] === ''))) {
                $this->invalid('rowContext');
            }
            $container = $registry->entries[$path]['field'];
            $locked = $locked || ! $container->isEditable($record);
            if ($container->relationName() === null) {
                if (array_key_exists('id', $identity)) {
                    $this->invalid('rowContext');
                }
                $stored = $json !== null ? data_get($json, $container->attributeName(), []) : ($storage !== null ? $container->storedJsonRowsForContext($storage, $record) : []);
                $rows = JsonRows::hydrate($stored ?? [], $root, $path, $storageAncestors);
                $json = collect($rows)->first(fn ($row) => $row['_brume']['key'] === $identity['key']) ?? [];
                $record = $root; // JSON callbacks retain the global nullable root.
            } else {
                $parent = $storage;
                $storage = $record = null;
                $json = null;
                if ($container instanceof RelationPicker) {
                    if (! isset($identity['id']) || $identity['key'] !== $identity['id']) {
                        $this->invalid('rowContext');
                    }
                    $sourceContext = $this->ancestor($path, $ancestors);
                    $lookup = new RowLookup($container->optionSource()->query($sourceContext));
                    $id = $lookup->normalizeId($identity['id']);
                    // Context identities must already use the source's canonical spelling.
                    // Decide existing/new only after this check; numeric aliases must not turn
                    // an owned intermediary into a new row with different readonly defaults.
                    if ($id === null || (string) $id !== $identity['id']) {
                        $this->invalid('rowContext');
                    }
                    if ($parent !== null) {
                        $relation = $container->relationship($parent);
                        if ($container->restoresRemoved()) {
                            $relation->withTrashed();
                        }
                        $attribute = $container->associationAttribute() ?? $relation->getRelated()->getKeyName();
                        $matches = $relation->where($relation->getRelated()->qualifyColumn($attribute), $identity['id'])->get();
                        $record = $matches->first(fn ($row) => (string) $row->getAttribute($attribute) === $identity['id']);
                        if ($record !== null && $relation instanceof BelongsToMany) {
                            $record = $record->getRelation($relation->getPivotAccessor());
                        }
                    }
                    $storage = $record;
                    if ($record === null) {
                        $selected = $lookup->find([$id])?->first();
                        if ($selected === null) {
                            $this->invalid('rowContext');
                        }
                        $relationship = $container->relationship($parent ?? $registry->entries[$path]['model']);
                        if ($relationship instanceof BelongsTo || ($relationship instanceof HasMany && $container->associationAttribute() === null)) {
                            $storage = $selected;
                        }
                    }
                    // Source identity is never used as the intermediary callback model.
                } elseif (isset($identity['id'])) {
                    if ($parent === null) {
                        $this->invalid('rowContext');
                    }
                    $lookup = new RowLookup($container->relationship($parent)->getQuery());
                    $id = $lookup->normalizeId($identity['id']);
                    $record = $id === null ? null : $lookup->find([$id])?->first();
                    if ($record === null || (string) $id !== $identity['id']) {
                        $this->invalid('rowContext');
                    }
                    $storage = $record;
                }
            }
            $ancestors[] = ['path' => $identity['path'], 'key' => $identity['key']] + (isset($identity['id']) ? ['id' => $identity['id']] : []);
            $storageAncestors[] = $container instanceof Repeater && $container->relationName() !== null && $record !== null
                ? ['path' => $path, 'key' => 'record:'.$record->getKey(), 'id' => (string) $record->getKey()]
                : end($ancestors);
        }

        return [$record, $json, $locked, $storage];
    }

    public function chain(mixed $chain): array
    {
        if (! is_array($chain) || ! array_is_list($chain)) {
            $this->invalid('rowContext');
        }

        return array_map(function ($item) {
            if (! $item instanceof stdClass) {
                $this->invalid('rowContext');
            }
            $item = get_object_vars($item);
            if (array_diff(array_keys($item), ['path', 'key', 'id']) !== [] || ! is_string($item['path'] ?? null) || ! is_string($item['key'] ?? null) || (array_key_exists('id', $item) && ! is_string($item['id']))) {
                $this->invalid('rowContext');
            }

            return ['path' => $item['path'], 'key' => $item['key']] + (isset($item['id']) ? ['id' => $item['id']] : []);
        }, $chain);
    }

    private function invalid(string $key): never
    {
        throw ValidationException::withMessages([$key => 'Invalid options context.']);
    }
}
