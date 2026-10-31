<?php

namespace Brume\Form;

use Brume\Form\Fields\Field;
use Brume\Form\Fields\RelationPicker;
use Brume\Form\Fields\Repeater;
use Illuminate\Database\Eloquent\Model;

/** Read-only traversal. Every callback receives the scoped model, never a JSON storage carrier. */
final class FormSnapshot
{
    public array $rowFields = [];

    private array $contexts = [];

    public function values(Form $form, ?Model $record, ?array $json = null, array $context = [], string $template = '', ?Model $root = null): array
    {
        $values = $capabilities = [];
        foreach ($form->fields() as $path => $field) {
            if ($context !== []) {
                $capabilities[$path] = ['readOnly' => $field->isReadOnly($record), 'rules' => ClientRules::for($field, $path, $record)];
            }
            data_set($values, $path, $this->value($field, $record, $json, $context, $template.$path, $root));
        }
        if ($context !== []) {
            $key = json_encode($context, JSON_THROW_ON_ERROR);
            if (isset($this->contexts[$key])) {
                throw new \InvalidArgumentException('Duplicate resolved row context.');
            }
            $this->contexts[$key] = true;
            $this->rowFields[] = ['row' => $context, 'fields' => $capabilities === [] ? (object) [] : $capabilities];
        }

        return $values;
    }

    public function value(Field $field, ?Model $record, ?array $json = null, array $context = [], ?string $containerPath = null, ?Model $root = null): mixed
    {
        if (! $field instanceof Repeater && ! $field instanceof RelationPicker) {
            return $json !== null ? $field->valueFromJson($json) : $field->valueFor($record);
        }
        $containerPath ??= $field->path();
        $rows = [];
        $relation = $field->relationName() !== null;
        if ($relation) {
            if ($record !== null && in_array($field->relationName(), $record->getHidden(), true)) {
                return $field instanceof RelationPicker && ! $field->settings()['multiple'] ? null : [];
            }
            $query = $record !== null ? $field->relationship($record) : null;
            if ($field instanceof Repeater && $field->orderAttribute() !== null) {
                $query?->orderBy($field->orderAttribute());
            }
            $stored = $query?->get()->all() ?? [];
        } else {
            $stored = $json !== null ? data_get($json, $field->attributeName(), []) : ($record !== null ? $field->storedJsonRows($record) : []);
            $stored = JsonRows::hydrate($stored ?? [], $root, $containerPath, $context);
        }
        foreach ($stored as $row) {
            if ($field instanceof RelationPicker) {
                $id = (string) ($field->associationAttribute() !== null ? $row->getAttribute($field->associationAttribute()) : $row->getKey());
                $identity = ['key' => $id, 'id' => $id];
                $metadata = ['id' => $id];
            } else {
                $identity = $relation ? ['key' => 'record:'.$row->getKey(), 'id' => (string) $row->getKey()] : $row['_brume'];
                $metadata = ['_brume' => $identity];
            }
            $rowContext = [...$context, ['path' => $containerPath] + $identity];
            $childTemplate = $containerPath.($field instanceof Repeater || $field->settings()['multiple'] ? '.*.' : '.');
            $rows[] = $metadata + $this->values($field->rowForm(), $relation ? $row : $root, $relation ? null : $row, $rowContext, $childTemplate, $root);
        }

        return $field instanceof RelationPicker && ! $field->settings()['multiple'] ? ($rows[0] ?? null) : $rows;
    }
}
