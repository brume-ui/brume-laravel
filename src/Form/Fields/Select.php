<?php

namespace Brume\Form\Fields;

class Select extends Field
{
    protected array $schema = ['options' => []];

    public function type(): string
    {
        return 'select';
    }

    public function options(array $options): static
    {
        $this->schema['options'] = [];
        foreach ($options as $value => $label) {
            $option = ['value' => (string) $value, 'label' => is_array($label) ? (string) $label[0] : (string) $label];
            if (is_array($label) && isset($label[1]) && in_array($this->type(), ['select', 'multipleSelect'], true)) {
                $option['depth'] = max(0, (int) $label[1]);
            }
            $this->schema['options'][] = $option;
        }

        return $this;
    }

    protected function normalizeValue(mixed $value): mixed
    {
        $value = parent::normalizeValue($value);

        return $value === null ? null : (string) $value;
    }
}
