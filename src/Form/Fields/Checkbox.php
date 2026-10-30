<?php

namespace Brume\Form\Fields;

class Checkbox extends MultipleSelect
{
    public function type(): string
    {
        return 'checkbox';
    }
}
