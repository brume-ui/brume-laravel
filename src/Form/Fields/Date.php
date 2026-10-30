<?php

namespace Brume\Form\Fields;

use DateTimeInterface;
use Illuminate\Support\Carbon;

class Date extends Field
{
    public function type(): string
    {
        return 'date';
    }

    protected function normalizeValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof DateTimeInterface ? $value : Carbon::parse($value))->format('Y-m-d');
    }
}
