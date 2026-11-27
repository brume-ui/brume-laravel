<?php

namespace Brume\Form\Fields;

use Closure;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/** Two decimal bounds in two columns (`{ start, end }`), each optional; `start <= end` when both are present. */
class Range extends DateRange
{
    protected array $schema = ['decimals' => 0];

    public function type(): string
    {
        return 'range';
    }

    public function decimals(int $decimals): static
    {
        if ($decimals < 0) {
            throw new InvalidArgumentException('Decimals must be non-negative.');
        }
        $this->schema['decimals'] = $decimals;

        return $this;
    }

    public function impliedRules(): array
    {
        return [...parent::impliedRules(), ...(($this->schema['required'] ?? false) ? [$this->allBoundsBlankRule()] : [])];
    }

    /** The same shape the browser accepts: an optionally negative decimal, at most `decimals` fraction digits. */
    public function boundPattern(): string
    {
        $decimals = $this->schema['decimals'];

        return '^-?[0-9]+'.($decimals > 0 ? '(?:\\.[0-9]{1,'.$decimals.'})?' : '').'$';
    }

    public function boundRules(string $bound, array $values, string $path): array
    {
        $rules = ['nullable', 'string', 'regex:~'.$this->boundPattern().'~uD'];
        if ($bound === 'end') {
            $rules[] = function ($attribute, $value, Closure $fail) use ($values, $path) {
                $start = Arr::get($values, $path.'.start');
                $pattern = '~^-?[0-9]+(?:\\.[0-9]+)?$~D';
                if (is_string($start) && is_string($value) && preg_match($pattern, $start) && preg_match($pattern, $value) && self::compare($start, $value) > 0) {
                    $fail(__('brume::form.range_order'));
                }
            };
        }

        return $rules;
    }

    protected function storedBound(mixed $item): mixed
    {
        return is_string($item) ? (trim($item) === '' ? null : Decimal::canonical($item, $this->schema['decimals'])) : $item;
    }

    /** The handler of an Action form receives the stored form of both bounds, as `fill` writes them. */
    public function canonicalInput(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach (['start', 'end'] as $bound) {
            if (array_key_exists($bound, $value)) {
                $value[$bound] = $this->storedBound($value[$bound]);
            }
        }

        return $value;
    }

    protected function normalizeValue(mixed $value): mixed
    {
        return $value === null || $value === '' ? null : Decimal::canonical($value, $this->schema['decimals']);
    }

    /** Exact comparison of two plain decimal strings (`-?digits[.digits]`), with no float in sight. */
    public static function compare(string $a, string $b): int
    {
        $part = function (string $text): array {
            $negative = str_starts_with($text, '-');
            [$int, $fraction] = array_pad(explode('.', ltrim($text, '-'), 2), 2, '');
            $int = preg_replace('/^0+(?=[0-9])/', '', $int);
            $fraction = rtrim($fraction, '0');

            return [$int, $fraction, $negative && ! ($int === '0' && $fraction === '')];
        };
        [$aInt, $aFraction, $aNegative] = $part($a);
        [$bInt, $bFraction, $bNegative] = $part($b);
        if ($aNegative !== $bNegative) {
            return $aNegative ? -1 : 1;
        }
        $width = max(strlen($aInt), strlen($bInt));
        $length = max(strlen($aFraction), strlen($bFraction));
        $magnitude = strcmp(str_pad($aInt, $width, '0', STR_PAD_LEFT).str_pad($aFraction, $length, '0'), str_pad($bInt, $width, '0', STR_PAD_LEFT).str_pad($bFraction, $length, '0'));

        return ($aNegative ? -1 : 1) * ($magnitude <=> 0);
    }
}
