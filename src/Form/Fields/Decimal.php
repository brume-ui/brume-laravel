<?php

namespace Brume\Form\Fields;

use InvalidArgumentException;

class Decimal extends Number
{
    protected array $schema = ['decimals' => 2];

    public function type(): string
    {
        return 'decimal';
    }

    public function decimals(int $decimals): static
    {
        if ($decimals < 0) {
            throw new InvalidArgumentException('Decimals must be non-negative.');
        }
        $this->schema['decimals'] = $decimals;

        return $this;
    }

    /** Round decimal strings without converting potentially large amounts to binary floats. */
    protected function normalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (! preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]*))?$/D', (string) $value, $parts)) {
            return null;
        }
        $scale = $this->schema['decimals'];
        $whole = ltrim($parts[2], '0') ?: '0';
        $fraction = str_pad($parts[3] ?? '', $scale + 1, '0');
        $digits = $whole.substr($fraction, 0, $scale);
        if ((int) $fraction[$scale] >= 5) {
            $carry = 1;
            for ($i = strlen($digits) - 1; $i >= 0 && $carry; $i--) {
                $next = (int) $digits[$i] + $carry;
                $digits[$i] = (string) ($next % 10);
                $carry = intdiv($next, 10);
            }
            if ($carry) {
                $digits = '1'.$digits;
            }
        }
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $sign = $parts[1] === '-' && trim($digits, '0') !== '' ? '-' : '';

        return $sign.($scale === 0 ? $digits : substr($digits, 0, -$scale).'.'.substr($digits, -$scale));
    }
}
