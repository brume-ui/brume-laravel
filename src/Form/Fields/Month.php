<?php

namespace Brume\Form\Fields;

use Illuminate\Database\Eloquent\Model;

/** Months 1-12 with translated names. The stored value is the integer, not a padded `MM` string. */
class Month extends Select
{
    public function __construct(string $name, ?string $label = null)
    {
        parent::__construct($name, $label);
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = __('brume::form.months.'.$month);
        }
        $this->options($months);
    }

    public function canonicalInput(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^(?:[1-9]|1[0-2])$/D', $value) === 1 ? (int) $value : ($value === '' ? null : $value);
    }

    public function fill(Model $record, mixed $value): void
    {
        parent::fill($record, $this->canonicalInput($value));
    }
}
