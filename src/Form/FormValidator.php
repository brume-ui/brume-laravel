<?php

namespace Brume\Form;

use Brume\Form\Fields\Field;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

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
        return self::validateFields(self::editableFields($form, $record), self::editableValues($form, $values, $record), $record, $form->query()->getModel());
    }

    /** Validate full-path declarations identically for root fields and upload metadata. */
    public static function validateFields(array $fields, array $values, ?Model $record = null, ?Model $model = null): array
    {
        self::translations();
        $rules = $messages = $labels = $choiceOptions = [];
        foreach ($fields as $path => $field) {
            $rules[$path] = $field->serverRules($record, $model);
            $labels[$path] = $field->validationLabel();
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
        $validator = Validator::make($values, $rules, $messages, $labels);
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
        $validator->validate();

        return $values;
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
