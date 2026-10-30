<?php

namespace Brume\Form\Fields;

class MultipleSelect extends Select
{
    public function type(): string
    {
        return 'multipleSelect';
    }

    protected function normalizeValue(mixed $value): mixed
    {
        return array_map(fn ($item) => (string) ($item instanceof \BackedEnum ? $item->value : $item), array_values($value ?? []));
    }
}
