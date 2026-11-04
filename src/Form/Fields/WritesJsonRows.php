<?php

namespace Brume\Form\Fields;

use Brume\Form\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/** @internal Shared JSON row writer of JSON repeaters and JSON pickers. */
trait WritesJsonRows
{
    /**
     * Convert one validated row through its declared writers, seeded with its persisted raw row
     * so unknown and omitted values survive. $owner is this row's context entry for descendants.
     */
    protected static function convertJsonRow(Form $rowForm, array $row, array $raw, ?Model $root, string $template, array $context, array $owner): array
    {
        // Conversion carrier only; validation already used the original nullable root.
        $carrier = new class extends Model {};
        foreach ($raw as $attribute => $storedValue) {
            if (is_array($storedValue) || $storedValue instanceof \stdClass) {
                $carrier->mergeCasts([$attribute => 'json']);
            }
            $carrier->setAttribute($attribute, $storedValue);
        }
        foreach ($rowForm->fields() as $path => $field) {
            if (! Arr::has($row, $path) || $field->type() === 'display' || ($field->settings()['displayOnly'] ?? false)) {
                continue;
            }
            foreach ($field->mappedAttributes() ?: [$field->attributeName()] as $attribute) {
                if (str_contains($attribute, '.')) {
                    $carrier->mergeCasts([explode('.', $attribute, 2)[0] => 'array']);
                }
            }
            if (($field instanceof Repeater || $field instanceof RelationPicker) && $field->relationName() === null) {
                $field->fillWithOriginal($carrier, data_get($row, $path), data_get($raw, $field->attributeName(), []) ?? [], $root, $template.'.'.$path, [...$context, $owner]);
            } else {
                if ($field instanceof Upload && ($field->settings()['multiple'] || $field->extraFields() !== [])) {
                    $carrier->mergeCasts([explode('.', $field->attributeName())[0] => 'json']);
                }
                $field->fill($carrier, data_get($row, $path));
            }
        }
        $converted = $carrier->attributesToArray();
        foreach ($carrier->getCasts() as $attribute => $cast) {
            if (in_array($cast, ['array', 'json'], true) && is_string($carrier->getAttributes()[$attribute] ?? null)) {
                $converted[$attribute] = json_decode($carrier->getAttributes()[$attribute], false, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $converted;
    }
}
