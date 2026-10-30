<?php

namespace Brume\Form;

use Brume\Form\Fields\Field;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

/** Uses Laravel's own message formatter, replacements and translation precedence. */
class ClientRules
{
    public static function for(Field $field, string $path, ?Model $record = null): array
    {
        if (! $field->isEditable($record)) {
            return [];
        }
        FormValidator::translations();
        $rules = $field->serverRules($record, includeUnique: false);
        $numeric = count(array_intersect(['integer', 'numeric'], array_map(fn ($rule) => explode(':', $rule, 2)[0], array_filter($rules, 'is_string')))) > 0;
        $result = [];
        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }
            [$name, $parameters] = array_pad(explode(':', $rule, 2), 2, '');
            $client = ['rule' => $name];
            if (in_array($name, ['min', 'max'], true)) {
                $array = in_array($field->type(), ['multipleSelect', 'checkbox', 'tags', 'upload'], true);
                if ($array && ! ($field->type() === 'upload' && ($field->settings()['multiple'] ?? false) && $name === 'max')) {
                    continue;
                }
                $client['rule'] = $array ? 'maxFiles' : ($numeric ? $name : $name.'Length');
                $client['value'] = $numeric ? $parameters + 0 : (int) $parameters;
            } elseif ($name === 'regex') {
                if ($field->validationPattern() === null || ! in_array($rule, $field->impliedRules(), true)) {
                    continue;
                }
                $client = ['rule' => 'pattern', 'pattern' => $field->validationPattern()];
            } elseif (! in_array($name, ['required', 'email', 'url', 'integer', 'numeric'], true)) {
                continue;
            }
            $messages = [];
            foreach ($field->validationMessages() as $key => $message) {
                $messages[$path.'.'.$key] = $message;
            }
            // A failed-rule entry lets Laravel render the exact message without probing arbitrary
            // failing samples (which cannot represent every valid regex or numeric boundary).
            $validator = Validator::make([], [$path => ['nullable']], $messages, [$path => $field->validationLabel()]);
            $validator->errors();
            $validator->setRules([$path => $rules]);
            $validator->addFailure($path, ucfirst($name), $parameters === '' ? [] : explode(',', $parameters));
            $client['message'] = $validator->errors()->first($path);
            $result[] = $client;
        }

        return $result;
    }
}
