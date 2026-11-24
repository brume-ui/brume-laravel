<?php

namespace Brume\Html;

use HTMLPurifier_Injector;
use HTMLPurifier_Token_Empty;

/**
 * Runtime backstop inside HTMLPurifier's MakeWellFormed stage, for max_elements and for the byte
 * budget: it counts every element that stage emits (the copies it makes when it reopens
 * formatting elements included), charges each one the bytes of its tags and attributes, charges
 * text, and throws HtmlRejected as soon as either total passes its limit. A reopened copy carries the
 * original's attributes and is validated and serialised on its own, so the stages after this one
 * do work proportional to those bytes; they stay bounded even if {@see MeasuringLexer}'s
 * projection were wrong.
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

    /** Bytes charged for those elements and for text. */
    public int $bytes = 0;

    public function __construct(
        private readonly int $max,
        private readonly int $byteBudget = PHP_INT_MAX,
        private readonly int $maxBytes = 0,
    ) {}

    /**
     * @param  \HTMLPurifier_Config  $config
     * @param  \HTMLPurifier_Context  $context
     */
    public function prepare($config, $context): bool|string
    {
        $this->count = 0;
        $this->bytes = 0;

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
            $this->tick(MeasuringLexer::elementBytes($token->name, $token->attr));
        }
    }

    /** @param  \HTMLPurifier_Token  $token */
    public function handleEnd(&$token): void
    {
        $start = $token->start;
        // Measured per emitted copy, not cached: the measuring is itself bounded by the budget.
        // A list or table part also pays for the bare <li> or <tbody> the next stage may add to it.
        $this->tick(($start === null ? 0 : MeasuringLexer::elementBytes($start->name, $start->attr)) + MeasuringLexer::insertedBytes($token->name));
    }

    /**
     * Text counts against the byte budget too (escaped, as it will be written), so the output
     * stays bounded here even for input that is nothing but `&`.
     *
     * @param  \HTMLPurifier_Token_Text  $token
     */
    public function handleText(&$token): void
    {
        $this->charge(strlen(htmlspecialchars($token->data, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8')));
    }

    private function tick(int $bytes): void
    {
        if (++$this->count > $this->max) {
            throw new HtmlRejected('elements', $this->max);
        }
        $this->charge($bytes);
    }

    private function charge(int $bytes): void
    {
        if (($this->bytes += $bytes) > $this->byteBudget) {
            throw new HtmlRejected('bytes', $this->maxBytes);
        }
    }
}
