<?php

namespace Brume\Form\Fields;

use DateTimeZone;

/** A searchable list of PHP time zone identifiers; the identifier itself is stored. */
class Timezone extends Select
{
    public function __construct(string $name, ?string $label = null)
    {
        parent::__construct($name, $label);
        $identifiers = DateTimeZone::listIdentifiers();
        $this->options(array_combine($identifiers, $identifiers))->searchable();
    }

    public function impliedRules(): array
    {
        return [...parent::impliedRules(), 'timezone:all'];
    }
}
