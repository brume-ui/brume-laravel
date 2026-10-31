<?php

namespace Brume\Form;

use Brume\Form\Fields\CustomField;
use Brume\Form\Fields\Field;
use Brume\Form\Fields\RelationPicker;
use Brume\Form\Fields\Repeater;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Validation and persistence share this declaration-based editable allowlist. */
class FormValidator
{
    public static function editableFields(Form $form, ?Model $record = null): array
    {
        return array_filter($form->fields(), fn (Field $field) => $field->isEditable($record));
    }

    public static function editableValues(Form $form, array $values, ?Model $record = null): array
    {
        $result = [];
        foreach (self::editableFields($form, $record) as $path => $field) {
            if (Arr::has($values, $path)) {
                Arr::set($result, $path, Arr::get($values, $path));
            }
        }

        return $result;
    }

    public static function validate(Form $form, array $values, ?Model $record = null): array
    {
        new DeclarationRegistry($form->fields(), $record ?? $form->query()->getModel());

        return self::validateFields($form->fields(), $values, $record, $form->query()->getModel());
    }

    /** Validate full-path declarations identically for root fields and upload metadata. */
    public static function validateFields(array $fields, array $values, ?Model $record = null, ?Model $model = null, ?array $rootValues = null, ?string $rowPath = null, ?array $storedValues = null, array $rowContext = [], string $templatePrefix = '', array $rootDefinitions = [], array $invalidPaths = [], array $conditionControllers = []): array
    {
        self::translations();
        $values = self::structuralValues($fields, $values);
        if ($rowPath === null) {
            $registry = new DeclarationRegistry($fields, $record ?? $model);
            foreach ($registry->entries as $entry) {
                foreach (['showWhen', 'enableWhen'] as $condition) {
                    if (isset($entry['field']->settings()[$condition])) {
                        foreach (Conditions::fromArray($entry['field']->settings()[$condition])->paths() as $dependency) {
                            $conditionControllers[$registry->resolve($dependency, $entry)] = true;
                        }
                    }
                }
            }
        }
        $authoritative = $values;
        $definitions = [];
        foreach ($fields as $path => $field) {
            $definitions[$path] = ['type' => $field->type()] + $field->settings();
            if (! $field->isEditable($record)) {
                Arr::set($authoritative, $path, $storedValues !== null ? $field->valueFromJson($storedValues) : $field->valueFor($record));
            }
        }
        $evaluation = $rootValues ?? $authoritative;
        if ($rowPath !== null) {
            Arr::set($evaluation, $rowPath, $authoritative);
        }
        $evaluationDefs = $rowPath === null ? $definitions : array_combine(array_map(fn ($p) => $rowPath.'.'.$p, array_keys($definitions)), $definitions);
        $evaluationDefs = array_replace($rootDefinitions, $evaluationDefs);
        if ($rowPath === null) {
            [$evaluation, $evaluationDefs] = self::jsonEvaluation($fields, $authoritative, $record);
        }
        $resolvedRules = [];
        foreach ($fields as $path => $field) {
            $resolvedRules[$path] = $field->isEditable($record) ? $field->serverRules($record, $model) : [];
            if ($field->isEditable($record) && $field->type() === 'switch' && isset($conditionControllers[$templatePrefix.$path])) {
                // Keep legacy boolean coercion for unrelated switches; condition operands are typed JSON.
                $resolvedRules[$path][] = function ($attribute, $value, $fail) use ($field) {
                    if (! is_bool($value)) {
                        $fail(__('validation.boolean', ['attribute' => $field->validationLabel()]));
                    }
                };
            }
            if ($field->isEditable($record) && Validator::make(JsonValues::validationView($authoritative), [$path => $resolvedRules[$path]])->fails()) {
                $invalidPaths[] = ($rowPath !== null ? $rowPath.'.' : '').$path;
            }
        }
        $active = [];
        foreach ($fields as $path => $field) {
            $enabled = $field->isEditable($record);
            foreach (['showWhen', 'enableWhen'] as $condition) {
                if (isset($field->settings()[$condition])) {
                    $enabled = Conditions::fromArray($field->settings()[$condition])->evaluate($evaluation, $rowPath, $evaluationDefs, $invalidPaths) && $enabled;
                }
            }
            if ($enabled) {
                $active[$path] = $field;
            }
        }
        $allFields = $fields;
        $fields = $active;
        $filtered = [];
        foreach ($fields as $path => $field) {
            if (Arr::has($values, $path)) {
                $input = Arr::get($values, $path);
                Arr::set($filtered, $path, in_array($field->type(), ['dateRange', 'datetimeRange', 'upload'], true) ? JsonValues::validationView($input) : $input);
            }
        }
        $values = $filtered;
        $rules = $messages = $labels = $choiceOptions = [];
        foreach ($fields as $path => $field) {
            $rules[$path] = $resolvedRules[$path];
            $labels[$path] = $field->validationLabel();
            if ($field instanceof CustomField) {
                foreach ($field->childValidationRules() as $child => $childRules) {
                    $rules[$path.'.'.$child] = $childRules;
                    $labels[$path.'.'.$child] = $field->validationLabel();
                }
            }
            $messagePaths = [$path];
            if (in_array($field->type(), ['multipleSelect', 'checkbox', 'tags'], true)) {
                $messagePaths[] = $path.'.*';
                $rules[$path.'.*'] = ['string'];
                if (in_array($field->type(), ['multipleSelect', 'checkbox'], true) && isset($field->settings()['options'])) {
                    $rules[$path.'.*'][] = $field->choiceRule();
                    $choiceOptions[$path] = array_column($field->settings()['options'], 'value');
                }
                if (isset($field->settings()['maxLength'])) {
                    $rules[$path.'.*'][] = 'max:'.$field->settings()['maxLength'];
                }
                $labels[$path.'.*'] = $field->validationLabel();
            }
            if (in_array($field->type(), ['dateRange', 'datetimeRange'], true)) {
                $format = $field->type() === 'dateRange' ? 'Y-m-d' : 'Y-m-d\\TH:i';
                foreach (['start', 'end'] as $bound) {
                    $messagePaths[] = $path.'.'.$bound;
                    $rules[$path.'.'.$bound] = ['nullable', 'date_format:'.$format];
                    $labels[$path.'.'.$bound] = $field->validationLabel();
                }
                if (Arr::get($values, $path.'.start') !== null) {
                    $rules[$path.'.end'][] = 'after_or_equal:'.$path.'.start';
                }
            }
            foreach ($messagePaths as $messagePath) {
                foreach ($field->validationMessages() as $rule => $message) {
                    $messages[$messagePath.'.'.$rule] = $message;
                }
            }
        }
        $validator = Validator::make(JsonValues::validationView($values), $rules, $messages, $labels);
        $validator->after(function ($validator) use ($fields, $values, $definitions) {
            foreach ($fields as $path => $field) {
                if (in_array($field->type(), ['repeater', 'relation', 'multipleSelect', 'checkbox', 'tags'], true) && ! Conditions::canonical($definitions[$path], Arr::get($values, $path))[0]) {
                    $validator->addFailure($path, 'Array');
                }
            }
        });
        $validator->after(function ($validator) use ($values, $choiceOptions) {
            // Laravel skips non-implicit rules for blank strings. Membership still applies
            // to every supplied item, including an explicitly declared blank option.
            foreach ($choiceOptions as $path => $options) {
                $items = Arr::get($values, $path);
                if (! is_array($items)) {
                    continue;
                }
                foreach ($items as $index => $item) {
                    $childPath = $path.'.'.$index;
                    if (! $validator->errors()->has($childPath) && (! is_string($item) || ! in_array($item, $options, true))) {
                        $validator->addFailure($childPath, 'In', $options);
                    }
                }
            }
        });
        $validator->after(function ($validator) use ($fields, $values, $evaluation, $rowPath) {
            foreach ($fields as $path => $field) {
                $own = Arr::get($values, $path);
                $own = is_array($own) ? $own : [$own];
                foreach ($field->settings()['excludeSelectedIn'] ?? [] as $dependency) {
                    $target = (new ConditionPath($dependency['scope'], $dependency['path']))->resolve($rowPath);
                    $candidates = self::expandPath($evaluation, explode('.', $target));
                    foreach ($candidates as [$candidatePath, $value]) {
                        if ($candidatePath === ($rowPath !== null ? $rowPath.'.' : '').$path) {
                            continue;
                        }
                        $others = is_array($value) ? $value : [$value];
                        foreach ($own as $selected) {
                            if ($selected !== null && $selected !== '' && in_array($selected, $others, true)) {
                                $validator->addFailure($path, 'Distinct');
                            }
                        }
                    }
                }
            }
        });
        $validator->validate();
        foreach ($fields as $path => $field) {
            if (! Arr::has($values, $path)) {
                continue;
            }
            if ($field instanceof RelationPicker || ($field instanceof Repeater && $field->relationName() !== null)) {
                throw new \LogicException('Relation validation requires the authoritative relation graph planner.');
            }
            if ($field instanceof Repeater) {
                $rows = Arr::get($values, $path) ?? [];
                $result = [];
                $keys = [];
                $stored = $storedValues !== null ? Arr::get($storedValues, $field->attributeName(), []) : ($record !== null ? $field->storedJsonRows($record) : []);
                $stored = JsonRows::hydrate($stored ?? [], $record, $templatePrefix.$path, $rowContext);
                $storedByKey = [];
                foreach ($stored as $storedRow) {
                    $storedByKey[$storedRow['_brume']['key']] = $storedRow;
                }
                foreach ($rows as $index => $row) {
                    $prefix = $path.'.'.$index;
                    if ($row instanceof \stdClass) {
                        $row = get_object_vars($row);
                    }
                    if (($row['_brume'] ?? null) instanceof \stdClass) {
                        $row['_brume'] = get_object_vars($row['_brume']);
                    }
                    $identity = is_array($row) ? ($row['_brume'] ?? null) : null;
                    if (! is_array($row) || ! is_array($identity) || array_keys($identity) !== ['key'] || ! is_string($identity['key']) || $identity['key'] === '' || in_array($identity['key'], $keys, true)) {
                        throw ValidationException::withMessages([$prefix => __('validation.array', ['attribute' => $field->validationLabel()])]);
                    }
                    $keys[] = $identity['key'];
                    unset($row['_brume']);
                    try {
                        $validated = self::validateFields($field->rowForm()->fields(), $row, $record, $model, $evaluation, ($rowPath !== null ? $rowPath.'.' : '').$prefix, $storedByKey[$identity['key']] ?? [], [...$rowContext, ['path' => $templatePrefix.$path] + $identity], $templatePrefix.$path.'.*.', $evaluationDefs, $invalidPaths, $conditionControllers);
                    } catch (ValidationException $e) {
                        throw ValidationException::withMessages(array_combine(array_map(fn ($child) => $prefix.'.'.$child, array_keys($e->errors())), $e->errors()));
                    }
                    $result[] = ['_brume' => $identity] + $validated;
                }
                foreach ($field->settings()['distinct'] ?? [] as $child) {
                    $seen = [];
                    $definition = $field->rowForm()->fields()[$child] ?? throw new \InvalidArgumentException('Unknown distinct declaration: '.$child);
                    foreach ($result as $index => $row) {
                        $origin = ($rowPath !== null ? $rowPath.'.' : '').$path.'.'.$index;
                        $currentValues = $evaluation;
                        Arr::set($currentValues, $origin, $row);
                        $currentDefs = $evaluationDefs;
                        foreach ($field->rowForm()->fields() as $relative => $candidate) {
                            $currentDefs[$origin.'.'.$relative] = ['type' => $candidate->type()] + $candidate->settings();
                        }
                        $activeChild = $definition->isEditable($record);
                        foreach (['showWhen', 'enableWhen'] as $condition) {
                            if (isset($definition->settings()[$condition])) {
                                $activeChild = Conditions::fromArray($definition->settings()[$condition])->evaluate($currentValues, $origin, $currentDefs) && $activeChild;
                            }
                        }
                        if (! $activeChild) {
                            continue;
                        }
                        $value = Arr::get($row, $child);
                        if ($value === null || $value === '') {
                            continue;
                        }
                        $canonical = Conditions::canonical(['type' => $definition->type()] + $definition->settings(), $value)[1];
                        $key = json_encode($canonical);
                        if (isset($seen[$key])) {
                            throw ValidationException::withMessages([$path.'.'.$index.'.'.$child => __('validation.distinct', ['attribute' => $definition->validationLabel()])]);
                        }
                        $seen[$key] = true;
                    }
                }
                Arr::set($values, $path, $result);
            }
        }
        if ($storedValues !== null) {
            foreach ($definitions as $path => $definition) {
                if (! isset($fields[$path]) && (Arr::has($storedValues, $allFields[$path]->attributeName()) || $allFields[$path]->mappedAttributes() !== [])) {
                    Arr::set($values, $path, $allFields[$path]->valueFromJson($storedValues));
                }
            }
        }

        return $values;
    }

    private static function structuralValues(array $fields, array $values): array
    {
        // Embeds are structural paths; only declared custom/collection values retain semantic
        // objects. This keeps Arr's established traversal working without flattening custom JSON.
        $semantic = $values;
        $values = JsonValues::validationView($values);
        $missing = new \stdClass;
        foreach ($fields as $path => $field) {
            if (in_array($field->type(), ['custom', 'repeater', 'relation', 'multipleSelect', 'checkbox', 'tags'], true)) {
                $value = data_get($semantic, $path, $missing);
                if ($value !== $missing) {
                    Arr::set($values, $path, $value);
                }
            }
        }

        return $values;
    }

    /** Pure JSON evaluation preparation; relation graph/model resolution remains the planner's job. */
    private static function jsonEvaluation(array $fields, array $values, ?Model $root, ?array $stored = null, string $prefix = '', string $template = '', array $context = [], bool $locked = false): array
    {
        $values = self::structuralValues($fields, $values);
        $definitions = [];
        foreach ($fields as $path => $field) {
            $definitions[$prefix.$path] = ['type' => $field->type()] + $field->settings();
            $readOnly = $locked || ! $field->isEditable($root);
            if ($readOnly) {
                Arr::set($values, $path, $stored !== null ? $field->valueFromJson($stored) : $field->valueFor($root));
            }
            if (! $field instanceof Repeater || $field->relationName() !== null) {
                continue;
            }
            $rows = Arr::get($values, $path);
            if (! is_array($rows) || ! array_is_list($rows)) {
                continue;
            }
            $original = $stored !== null ? data_get($stored, $field->attributeName(), []) : ($root !== null ? $field->storedJsonRows($root) : []);
            $byKey = [];
            foreach (JsonRows::hydrate($original ?? [], $root, $template.$path, $context) as $originalRow) {
                $byKey[$originalRow['_brume']['key']] = $originalRow;
            }
            if ($readOnly) {
                // These positions belong to authoritative storage, never submitted rows.
                $rows = JsonRows::hydrate($rows, $root, $template.$path, $context);
            }
            foreach ($rows as $index => $row) {
                if ($row instanceof \stdClass) {
                    $row = get_object_vars($row);
                }
                if (! is_array($row) || array_is_list($row)) {
                    continue;
                }
                $key = data_get($row, '_brume.key');
                $identity = is_string($key) && $key !== '' ? $key : null;
                [$rows[$index], $children] = self::jsonEvaluation(
                    $field->rowForm()->fields(), $row, $root, $identity !== null ? ($byKey[$identity] ?? []) : [],
                    $prefix.$path.'.'.$index.'.', $template.$path.'.*.',
                    [...$context, ['path' => $template.$path, 'key' => $identity ?? '']], $readOnly,
                );
                $definitions = array_replace($definitions, $children);
            }
            Arr::set($values, $path, $rows);
        }

        return [$values, $definitions];
    }

    private static function expandPath(mixed $value, array $parts, string $base = ''): array
    {
        if ($parts === []) {
            return [[$base, $value]];
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        $segment = array_shift($parts);
        if ($segment === '*') {
            $result = [];
            foreach (is_array($value) ? $value : [] as $key => $child) {
                $result = [...$result, ...self::expandPath($child, $parts, $base === '' ? (string) $key : $base.'.'.$key)];
            }

            return $result;
        }

        return self::expandPath(is_array($value) ? ($value[$segment] ?? null) : null, $parts, $base === '' ? $segment : $base.'.'.$segment);
    }

    /** Add only missing lines, leaving host translations (including custom paths) authoritative. */
    public static function translations(): void
    {
        $translator = app('translator');
        $locale = $translator->getLocale();
        $file = __DIR__.'/../../lang/'.$locale.'/validation.php';
        if (! is_file($file)) {
            return;
        }
        $existing = $translator->get('validation', [], $locale, false);
        $existing = is_array($existing) ? $existing : [];
        $lines = [];
        foreach (array_replace_recursive(require $file, $existing) as $key => $value) {
            $lines['validation.'.$key] = $value;
        }
        $translator->addLines($lines, $locale);
    }
}
