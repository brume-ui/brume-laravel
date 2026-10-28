<?php

namespace Brume\Tests\Support\Controllers;

/**
 * Returns a plain array where a Grid is expected.
 */
class NotAGridController
{
    public function __invoke(): array
    {
        return ['not' => 'a grid'];
    }
}
