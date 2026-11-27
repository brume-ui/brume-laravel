<?php

namespace Brume\Form\Fields;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A 24-hour time. `HH:mm` and `HH:mm:ss` are both accepted whatever `seconds()` says; storage is
 * `HH:mm:ss`. Reading gives `HH:mm` on a field without seconds only when that loses nothing
 * (seconds are `:00`), so a stored `09:05:30` is never shifted by loading and saving it.
 */
class Time extends Field
{
    public const PATTERN = '^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$';

    public function normalizeBlank(mixed $input): mixed
    {
        return self::blankToNull($input);
    }

    public function type(): string
    {
        return 'time';
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

    public function validationPattern(): ?string
    {
        return self::PATTERN;
    }

    public function pattern(string $regex, ?string $message = null): static
    {
        throw new \LogicException('A time field has a fixed format.');
    }

    public function impliedRules(): array
    {
        return [...parent::impliedRules(), 'string'];
    }

    /** `HH:mm:ss` for a validated `HH:mm[:ss]`; blank is null. */
    public static function storage(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        if (trim($value) === '') {
            return null;
        }

        return strlen($value) === 5 ? $value.':00' : $value;
    }

    /** The value the form shows. Anything that is not a plain time passes through for the browser to flag. */
    public static function display(mixed $value, bool $seconds): mixed
    {
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('H:i:s');
        }
        if (! is_string($value)) {
            return null;
        }
        // Some drivers return a TIME column as `09:05:00.000000`: an all-zero fraction is dropped.
        if (! preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9])(?:\.0+)?)?$/D', $value, $parts)) {
            return trim($value) === '' ? null : $value;
        }
        $rest = $parts[3] ?? '00';

        return $parts[1].':'.$parts[2].(! $seconds && $rest === '00' ? '' : ':'.$rest);
    }

    protected function normalizeValue(mixed $value): mixed
    {
        return self::display($value, $this->schema['seconds'] ?? false);
    }

    public function canonicalInput(mixed $value): mixed
    {
        return self::storage($value);
    }

    public function fill(Model $record, mixed $value): void
    {
        parent::fill($record, self::storage($value));
    }
}
