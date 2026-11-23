<?php

namespace Brume\Html;

use HTMLPurifier_Injector;

/**
 * A `<col>` belongs in a table or colgroup. It is an empty element, so MakeWellFormed never
 * checks it against its parent, and a list would wrap a stray one into an `<li>` that is only
 * cleaned up on the next pass. Removed here, in the first pass.
 *
 * @internal
 */
final class StrayColumnInjector extends HTMLPurifier_Injector
{
    /** @var string */
    public $name = 'BrumeStrayColumn';

    /** @var array<string, mixed> */
    public $needed = [];

    /** @param  \HTMLPurifier_Token|false  $token */
    public function handleElement(&$token): void
    {
        if ($token->name !== 'col') {
            return;
        }
        $parent = $this->currentNesting[count($this->currentNesting) - 1] ?? null;
        if ($parent === null || ($parent->name !== 'table' && $parent->name !== 'colgroup')) {
            $token = false;
        }
    }
}
