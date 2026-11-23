<?php

namespace Brume\Html;

use HTMLPurifier_Injector;
use HTMLPurifier_Token_Empty;

/**
 * Runtime backstop for max_elements inside HTMLPurifier's MakeWellFormed stage: it counts every
 * element that stage emits (the copies it makes when it reopens formatting elements included)
 * and throws HtmlRejected as soon as there are more than the limit. Work and memory
 * therefore stay bounded even if {@see MeasuringLexer}'s projection were wrong.
 *
 * @internal
 */
final class ElementBudgetInjector extends HTMLPurifier_Injector
{
    /** @var string */
    public $name = 'BrumeElementBudget';

    /** @var array<string, mixed> */
    public $needed = [];

    /** Elements seen in the last (or current) run. */
    public int $count = 0;

    public function __construct(private readonly int $max) {}

    /**
     * @param  \HTMLPurifier_Config  $config
     * @param  \HTMLPurifier_Context  $context
     */
    public function prepare($config, $context): bool|string
    {
        $this->count = 0;

        return parent::prepare($config, $context);
    }

    /**
     * Empty elements are counted here. Start tags are counted at their end tag instead: the copies
     * MakeWellFormed makes when it reopens a formatting element are clones that inherit the
     * original's injector skips, so they never reach handleElement, but each of them is closed by a
     * new end tag that reaches handleEnd. Elements counted so far plus the open ones therefore
     * bound what has been built, and the open ones are bounded by the depth.
     *
     * @param  \HTMLPurifier_Token  $token
     */
    public function handleElement(&$token): void
    {
        if ($token instanceof HTMLPurifier_Token_Empty) {
            $this->tick();
        }
    }

    /** @param  \HTMLPurifier_Token  $token */
    public function handleEnd(&$token): void
    {
        $this->tick();
    }

    private function tick(): void
    {
        if (++$this->count > $this->max) {
            throw new HtmlRejected('elements', $this->max);
        }
    }
}
