<?php

namespace Brume\Form\Fields;

use DateTimeInterface;
use Illuminate\Support\Carbon;

class DatetimeRange extends DateRange
{
    public function type(): string
    {
        return 'datetimeRange';
    }

    protected function normalizeValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof DateTimeInterface ? $value : Carbon::parse($value))->format('Y-m-d\TH:i');
    }
}
