<?php

namespace Brume\Form\Fields;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/** A colour stored as canonical lowercase `#rrggbb` (`#rrggbbaa` when alpha is allowed). */
class Color extends Field
{
    private const MAX_SWATCHES = 24;

    public function type(): string
    {
        return 'color';
    }

    public function alpha(bool $on = true): static
    {
        if ($on) {
            $this->schema['alpha'] = true;
        } else {
            unset($this->schema['alpha']);
        }

        return $this;
    }

    /** @param list<string> $hex */
    public function swatches(array $hex): static
    {
        if (count($hex) > self::MAX_SWATCHES) {
            throw new InvalidArgumentException('A colour field takes at most '.self::MAX_SWATCHES.' swatches.');
        }
        $this->schema['swatches'] = array_map(function ($swatch) {
            // Alpha is checked against the field at render time, so either order of calls works.
            $canonical = is_string($swatch) ? self::canonical($swatch, true) : null;
            if ($canonical === null) {
                throw new InvalidArgumentException('Invalid colour swatch.');
            }

            return $canonical;
        }, array_values($hex));

        return $this;
    }

    public function validationPattern(): ?string
    {
        return $this->patternSource ?? (($this->schema['alpha'] ?? false)
            ? '^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$'
            : '^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$');
    }

    public function toSchema(?Model $record = null): array
    {
        if (! ($this->schema['alpha'] ?? false)) {
            foreach ($this->schema['swatches'] ?? [] as $swatch) {
                if (strlen($swatch) === 9) {
                    throw new InvalidArgumentException('Alpha swatches need alpha() on the colour field.');
                }
            }
        }

        return parent::toSchema($record);
    }

    /** Same rule as the frontend normalizeHex, except that a missing `#` is not added. */
    public function pattern(string $regex, ?string $message = null): static
    {
        throw new LogicException('A colour field has a fixed format; use alpha() to allow transparency.');
    }

    /** Blank and unrecognisable strings become null: validation rejects the latter before this runs. */
    public function canonicalInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return trim($value) === '' ? null : self::canonical($value, $this->schema['alpha'] ?? false, false);
    }

    public function fill(Model $record, mixed $value): void
    {
        parent::fill($record, $this->canonicalInput($value));
    }

    private static function canonical(string $input, bool $alpha, bool $optionalHash = true): ?string
    {
        $body = trim($input);
        if (str_starts_with($body, '#')) {
            $body = substr($body, 1);
        } elseif (! $optionalHash) {
            return null;
        }
        if (! preg_match('/^[0-9a-fA-F]+$/D', $body) || ! in_array(strlen($body), $alpha ? [3, 4, 6, 8] : [3, 6], true)) {
            return null;
        }
        if (strlen($body) <= 4) {
            $body = implode('', array_map(fn ($c) => $c.$c, str_split($body)));
        }

        return '#'.strtolower($body);
    }
}
