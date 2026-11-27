<?php

namespace Brume\Form\Fields;

/** Two time bounds in two columns. `start <= end` is not enforced: overnight ranges are legitimate. */
class TimeRange extends DateRange
{
    public function type(): string
    {
        return 'timeRange';
    }

    public function seconds(bool $on = true): static
    {
        if ($on) {
            $this->schema['seconds'] = true;
        } else {
            unset($this->schema['seconds']);
        }

        return $this;
    }

    public function impliedRules(): array
    {
        return [...parent::impliedRules(), ...(($this->schema['required'] ?? false) ? [$this->allBoundsBlankRule()] : [])];
    }

    public function boundRules(string $bound, array $values, string $path): array
    {
        return ['nullable', 'string', 'regex:~'.Time::PATTERN.'~uD'];
    }

    protected function storedBound(mixed $item): mixed
    {
        return Time::storage($item);
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
        return Time::display($value, $this->schema['seconds'] ?? false);
    }
}
