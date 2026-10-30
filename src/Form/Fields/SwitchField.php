<?php

namespace Brume\Form\Fields;

class SwitchField extends Field
{
    public function type(): string
    {
        return 'switch';
    }

    protected function normalizeValue(mixed $value): mixed
    {
        return $value === null ? false : (bool) $value;
    }
}
