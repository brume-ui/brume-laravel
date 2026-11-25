<?php

namespace Brume\Form\Fields;

use Illuminate\Database\Eloquent\Model;

/** A telephone number kept as typed (trimmed). An explicit pattern() replaces the default. */
class Tel extends Field
{
    public const PATTERN = '^(?:\\+886[- ]?|\\(?0)\\d{1,3}\\)?[- ]?\\d{2,4}[- ]?\\d{3,4}(?:(?:#| ?ext\\.? ?| ?分機 ?| ?轉 ?)\\d{1,6})?$';

    public function type(): string
    {
        return 'tel';
    }

    public function validationPattern(): ?string
    {
        return $this->patternSource ?? self::PATTERN;
    }

    public function canonicalInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return trim($value) === '' ? null : trim($value);
    }

    public function fill(Model $record, mixed $value): void
    {
        parent::fill($record, $this->canonicalInput($value));
    }
}
