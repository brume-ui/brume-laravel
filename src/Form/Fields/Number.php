<?php

namespace Brume\Form\Fields;

use InvalidArgumentException;

class Number extends Field
{
    public function type(): string
    {
        return 'number';
    }

    public function min(int|float $min): static
    {
        $this->schema['min'] = $min;

        return $this;
    }

    public function max(int|float $max): static
    {
        $this->schema['max'] = $max;

        return $this;
    }

    public function step(int|float $step): static
    {
        if ($step <= 0) {
            throw new InvalidArgumentException('Step must be positive.');
        }
        $this->schema['step'] = $step;

        return $this;
    }

    protected function normalizeValue(mixed $value): mixed
    {
        return $value === null ? null : (is_numeric($value) ? $value + 0 : null);
    }
}
