<?php

namespace Brume\Form\Fields;

use Illuminate\Database\Eloquent\Model;

class Select extends Field
{
    use HasRemoteOptions;

    public function toSchema(?Model $record = null): array
    {
        return $this->remoteSchema() + parent::toSchema($record);
    }

    protected array $schema = ['options' => []];

    public function type(): string
    {
        return 'select';
    }

    public function options(array $options): static
    {
        if ($this->source !== null) {
            throw new \InvalidArgumentException('Remote options cannot also declare static choices.');
        }
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

    /** A single select shows a clear button; `placeholder()` names the empty choice it returns to (null). */
    public function clearable(bool $on = true): static
    {
        if ($this->type() !== 'select') {
            throw new \InvalidArgumentException('Only a single select can be clearable.');
        }
        if ($on) {
            $this->schema['clearable'] = true;
        } else {
            unset($this->schema['clearable']);
        }

        return $this;
    }

    protected function normalizeValue(mixed $value): mixed
    {
        $value = parent::normalizeValue($value);

        return $value === null ? null : (string) $value;
    }
}
