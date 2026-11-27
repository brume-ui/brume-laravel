<?php

namespace Brume\Form\Fields;

use Illuminate\Database\Eloquent\Model;

/** A read-only display that only exists once the record does. */
class IdField extends Display
{
    public function omittedFromSchema(?Model $record): bool
    {
        return $record === null;
    }
}
