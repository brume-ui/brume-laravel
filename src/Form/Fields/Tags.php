<?php

namespace Brume\Form\Fields;

use InvalidArgumentException;

class Tags extends MultipleSelect
{
    protected array $schema = [];

    public function type(): string
    {
        return 'tags';
    }

    public function max(int $max): static
    {
        if ($max < 1) {
            throw new InvalidArgumentException('Max tags must be positive.');
        }
        $this->schema['max'] = $max;

        return $this;
    }

    public function maxLength(int $length): static
    {
        if ($length < 0) {
            throw new InvalidArgumentException('Max length must be non-negative.');
        }
        $this->schema['maxLength'] = $length;

        return $this;
    }
}
