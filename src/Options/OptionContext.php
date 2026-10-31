<?php

namespace Brume\Options;

use Illuminate\Database\Eloquent\Model;

final readonly class OptionContext
{
    public function __construct(public ?Model $record, public array $dependencies = [], public array $rowContext = []) {}
}
