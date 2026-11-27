<?php

namespace Brume\Form\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;

/** A lucide icon name in kebab-case. An unknown name is kept; `icons()` restricts the picker and the validation. */
class Icon extends Field
{
    public const PATTERN = '^[a-z0-9]+(?:-[a-z0-9]+)*$';

    public const MAX_LENGTH = 64;

    public const MAX_ICONS = 200;

    public function type(): string
    {
        return 'icon';
    }

    /** @param list<string> $names */
    public function icons(array $names): static
    {
        if (count($names) > self::MAX_ICONS) {
            throw new InvalidArgumentException('An icon field takes at most '.self::MAX_ICONS.' icons.');
        }
        foreach ($names as $name) {
            if (! is_string($name) || strlen($name) > self::MAX_LENGTH || ! preg_match('/'.self::PATTERN.'/D', $name)) {
                throw new InvalidArgumentException('Icon names are kebab-case lucide names of at most '.self::MAX_LENGTH.' characters.');
            }
        }
        $this->schema['icons'] = array_values($names);

        return $this;
    }

    public function validationPattern(): ?string
    {
        return self::PATTERN;
    }

    public function pattern(string $regex, ?string $message = null): static
    {
        throw new LogicException('An icon field has a fixed name format; use icons() to restrict the choice.');
    }

    public function impliedRules(): array
    {
        return [...parent::impliedRules(), 'string', 'max:'.self::MAX_LENGTH, ...(isset($this->schema['icons']) ? [Rule::in($this->schema['icons'])] : [])];
    }

    public function canonicalInput(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    public function fill(Model $record, mixed $value): void
    {
        parent::fill($record, $this->canonicalInput($value));
    }
}
