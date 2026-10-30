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
        self::translations();
        $values = self::editableValues($form, $values, $record);
        $rules = $messages = $labels = [];
        foreach (self::editableFields($form, $record) as $path => $field) {
            $rules[$path] = $field->serverRules($record, $form->query()->getModel());
            $labels[$path] = $field->validationLabel();
            foreach ($field->validationMessages() as $rule => $message) {
                $messages[$path.'.'.$rule] = $message;
            }
            if (in_array($field->type(), ['multipleSelect', 'checkbox', 'tags'], true)) {
                $rules[$path.'.*'] = ['string'];
                if (isset($field->settings()['options'])) {
                    $rules[$path.'.*'][] = $field->choiceRule();
                }
                if (isset($field->settings()['maxLength'])) {
                    $rules[$path.'.*'][] = 'max:'.$field->settings()['maxLength'];
                }
                $labels[$path.'.*'] = $field->validationLabel();
            }
            if (in_array($field->type(), ['dateRange', 'datetimeRange'], true)) {
                $format = $field->type() === 'dateRange' ? 'Y-m-d' : 'Y-m-d\\TH:i';
                foreach (['start', 'end'] as $bound) {
                    $rules[$path.'.'.$bound] = ['nullable', 'date_format:'.$format];
                    $labels[$path.'.'.$bound] = $field->validationLabel();
                }
                if (Arr::get($values, $path.'.start') !== null) {
                    $rules[$path.'.end'][] = 'after_or_equal:'.$path.'.start';
                }
            }
        }
        Validator::make($values, $rules, $messages, $labels)->validate();

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
