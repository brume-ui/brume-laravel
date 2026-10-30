<?php

namespace Brume\Form\Fields;

class Currency extends Decimal
{
    protected array $schema = ['decimals' => 0, 'symbol' => 'NT$'];

    public function type(): string
    {
        return 'currency';
    }

    public function symbol(string $symbol = 'NT$'): static
    {
        $this->schema['symbol'] = $symbol;

        return $this;
    }

    public function decimals(int $decimals = 0): static
    {
        return parent::decimals($decimals);
    }
}
