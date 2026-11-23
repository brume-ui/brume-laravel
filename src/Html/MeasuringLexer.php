<?php

namespace Brume\Html;

use DOMDocument;
use DOMElement;
use DOMNode;
use HTMLPurifier_Config;
use HTMLPurifier_Context;
use HTMLPurifier_Lexer_DOMLex;

/**
 * HTMLPurifier's libxml lexer, measuring the tree it builds before tokenising it.
 *
 * Depth and element count are read from the very DOM HTMLPurifier would tokenise (same
 * normalisation, wrapper and loadHTML flags as {@see HTMLPurifier_Lexer_DOMLex}), so no input
 * can look shallow to the check and be deep to the parser. Over a limit, tokenising stops with
 * {@see HtmlRejected} before HTMLPurifier's own (recursive, memory-hungry) stages run.
 * {@see self::violation()} runs the same parse and walk without tokenising.
 *
 * libxml itself parses, walks and frees 1 000 000 nested levels without trouble; the crashes
 * seen on deep input happen in the stages after the lexer.
 *
 * @internal
 */
final class MeasuringLexer extends HTMLPurifier_Lexer_DOMLex
{
    public function __construct(
        private readonly ?int $maxDepth,
        private readonly ?int $maxElements,
    ) {
        parent::__construct();
    }

    /**
     * @return 'depth'|'elements'|null
     */
    public function violation(string $html, HTMLPurifier_Config $config, HTMLPurifier_Context $context): ?string
    {
        if ($this->maxDepth === null && $this->maxElements === null) {
            return null;
        }
        [, $body, $div] = $this->load($html, $config, $context);

        return $this->measure($body, $div);
    }

    /**
     * Same as the parent, with the measurement between parsing and tokenising.
     *
     * @param  string  $html
     * @param  HTMLPurifier_Config  $config
     * @param  HTMLPurifier_Context  $context
     * @return list<\HTMLPurifier_Token>
     */
    public function tokenizeHTML($html, $config, $context): array
    {
        [, $body, $div] = $this->load($html, $config, $context);
        $violation = $this->measure($body, $div);
        if ($violation !== null) {
            throw new HtmlRejected($violation, $violation === 'depth' ? $this->maxDepth : $this->maxElements);
        }

        $tokens = [];
        $this->tokenizeDOM($div, $tokens, $config);
        // A premature </div> closed the wrapper: tokenise the rest of the body too (as the parent does).
        if ($div->nextSibling) {
            $body->removeChild($div);
            $this->tokenizeDOM($body, $tokens, $config);
        }

        return $tokens;
    }

    /**
     * The parent's parsing steps, verbatim.
     *
     * @return array{DOMDocument, DOMElement, DOMElement}
     */
    private function load(string $html, HTMLPurifier_Config $config, HTMLPurifier_Context $context): array
    {
        $html = $this->normalize($html, $config, $context);
        if ($config->get('Core.AggressivelyFixLt')) {
            $html = $this->aggressivelyFixLt($html);
        }
        $html = $this->wrapHTML($html, $config, $context);

        $doc = new DOMDocument;
        $doc->encoding = 'UTF-8';
        $options = 0;
        if ($config->get('Core.AllowParseManyTags') && defined('LIBXML_PARSEHUGE')) {
            $options |= LIBXML_PARSEHUGE;
        }
        if ($config->get('Core.RemoveBlanks') && defined('LIBXML_NOBLANKS')) {
            $options |= LIBXML_NOBLANKS;
        }
        set_error_handler([$this, 'muteErrorHandler']);
        try {
            $options ? $doc->loadHTML($html, $options) : $doc->loadHTML($html);
        } finally {
            restore_error_handler();
        }

        $body = $doc->getElementsByTagName('html')->item(0)->getElementsByTagName('body')->item(0);
        $div = $body->getElementsByTagName('div')->item(0);

        return [$doc, $body, $div];
    }

    /**
     * Iterative walk (no recursion) of the body. The wrapper div is neither counted nor a level.
     * Stops at the first limit passed.
     *
     * @return 'depth'|'elements'|null
     */
    private function measure(DOMElement $body, DOMElement $wrapper): ?string
    {
        if ($this->maxDepth === null && $this->maxElements === null) {
            return null;
        }
        $count = 0;
        $depth = 1;
        /** @var DOMNode|null $node */
        $node = $body->firstChild;
        while ($node !== null) {
            if ($node->nodeType === XML_ELEMENT_NODE && $node !== $wrapper) {
                if ($this->maxElements !== null && ++$count > $this->maxElements) {
                    return 'elements';
                }
                if ($this->maxDepth !== null && $depth > $this->maxDepth) {
                    return 'depth';
                }
            }
            if ($node->firstChild !== null) {
                if ($node !== $wrapper) {
                    $depth++;
                }
                $node = $node->firstChild;

                continue;
            }
            while ($node !== null && $node->nextSibling === null) {
                $node = $node->parentNode;
                if ($node === $body) {
                    return null;
                }
                if ($node !== $wrapper) {
                    $depth--;
                }
            }
            $node = $node?->nextSibling;
        }

        return null;
    }
}
