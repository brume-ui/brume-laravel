<?php

namespace Brume\Form\Fields;

/** A percentage: an ungrouped decimal string like `decimal`, shown with a % suffix. Bounds are opt-in. */
class Rate extends Decimal
{
    protected array $schema = ['decimals' => 0];

    public function normalizeBlank(mixed $input): mixed
    {
        return self::blankToNull($input);
    }

    public function type(): string
    {
        return 'rate';
    }
}
