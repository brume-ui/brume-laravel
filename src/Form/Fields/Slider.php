<?php

namespace Brume\Form\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** A number on the grid `min + k * step` between min and max; off-grid values are rejected, never rounded. */
class Slider extends Number
{
    protected array $schema = ['min' => 0, 'max' => 100, 'step' => 1];

    private bool $minSet = false;

    private bool $maxSet = false;

    public function type(): string
    {
        return 'slider';
    }

    public function min(int|float $min): static
    {
        $this->minSet = true;
        parent::min($min);
        if ($this->maxSet) {
            $this->assertBounds();
        }

        return $this;
    }

    public function max(int|float $max): static
    {
        $this->maxSet = true;
        parent::max($max);
        if ($this->minSet) {
            $this->assertBounds();
        }

        return $this;
    }

    private function assertBounds(): void
    {
        if ($this->schema['min'] >= $this->schema['max']) {
            throw new InvalidArgumentException('A slider needs min below max.');
        }
    }

    public function toSchema(?Model $record = null): array
    {
        $this->assertBounds();

        return parent::toSchema($record);
    }

    public function impliedRules(): array
    {
        $this->assertBounds();

        return [...parent::impliedRules(), function ($attribute, $value, Closure $fail) {
            if (is_numeric($value) && ! self::onGrid((float) $value, $this->schema['min'], $this->schema['step'])) {
                $fail(__('brume::form.slider_step', ['step' => $this->schema['step'], 'min' => $this->schema['min']]));
            }
        }];
    }

    /**
     * Whether `$value - $min` is a whole number of steps, counted on integers scaled by the
     * fraction digits of min and step so that 0.3 on a 0.1 grid carries no float error. Float noise
     * far below the scale (the 1e-9 the browser also ignores) is forgiven; 0.35 is not.
     */
    public static function onGrid(float $value, int|float $min, int|float $step): bool
    {
        $scale = 10 ** max(self::fractionDigits($min), self::fractionDigits($step));
        $scaled = $value * $scale;
        $rounded = round($scaled);
        if (abs($scaled - $rounded) > max(5e-10, abs($rounded) * 4 * PHP_FLOAT_EPSILON)) {
            return false;
        }
        $unit = round($step * $scale);

        return $unit > 0 && fmod($rounded - round($min * $scale), $unit) === 0.0;
    }

    private static function fractionDigits(int|float $number): int
    {
        // Twelve digits is the most a step of a UI control can usefully carry.
        $text = rtrim(rtrim(sprintf('%.12F', $number), '0'), '.');

        return str_contains($text, '.') ? strlen(substr($text, strpos($text, '.') + 1)) : 0;
    }

    public function canonicalInput(mixed $value): mixed
    {
        return is_numeric($value) ? $value + 0 : (is_string($value) && trim($value) === '' ? null : $value);
    }

    public function fill(Model $record, mixed $value): void
    {
        parent::fill($record, $this->canonicalInput($value));
    }
}
