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
    /** @var array<string, array<string, true>> allowed children by element name */
    private array $allowed = [];

    public function __construct(
        private readonly ?int $maxDepth,
        private readonly ?int $maxElements,
        private readonly bool $projection = true,
    ) {
        parent::__construct();
    }

    /**
     * Depth, element count and projected element count of the tree, without stopping at limits.
     *
     * @return array{depth: int, elements: int, projected: int}
     */
    public function stats(string $html, HTMLPurifier_Config $config, HTMLPurifier_Context $context): array
    {
        [, $body, $div] = $this->load($html, $config, $context);

        return $this->walk($body, $div, $config, false)['stats'];
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

        return $this->walk($body, $div, $config, true)['violation'];
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
        $violation = $this->maxDepth === null && $this->maxElements === null
            ? null
            : $this->walk($body, $div, $config, true)['violation'];
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
     * Iterative walk (no recursion) of the body; the wrapper div is neither counted nor a level.
     *
     * "projected" bounds the elements HTMLPurifier will emit, which can be more than libxml built:
     *  - MakeWellFormed closes an open formatting element (definition flag `formatting`: b, i, u,
     *    s, em, strong, a) before a child that element does not allow (a block inside <b>, an <li>
     *    inside <a>) and reopens a copy inside that child; when the child closes with the copy
     *    still open, it reopens another copy after it. So each element costs at most 2 × the
     *    number of its formatting ancestors that do not allow it, on top of itself. Reopened
     *    copies are reopened again inside every following child, which is counted at that child.
     *  - ul/ol: one <li> inserted when a list opens inside a list (`wrap`), one <li> created around
     *    stray content (ChildDef_List).
     *  - table: one <tbody> around a leading run of rows, one after each <tbody> (ChildDef_Table).
     * Every element counts at least 1, known to the definition or not. ElementBudgetInjector
     * enforces the limit again at runtime, so an error here cannot exhaust memory.
     *
     * @param  bool  $stopAtLimit  return as soon as a limit is passed
     * @return array{violation: 'depth'|'elements'|null, stats: array{depth: int, elements: int, projected: int}}
     */
    private function walk(DOMElement $body, DOMElement $wrapper, HTMLPurifier_Config $config, bool $stopAtLimit): array
    {
        $definition = $config->getHTMLDefinition();
        $project = $this->projection || ! $stopAtLimit;
        $count = 0;
        $projected = 0;
        $depth = 1;
        $maxDepth = 0;
        /** @var array<string, int> $open open formatting ancestors by name */
        $open = [];

        /** @var DOMNode|null $node */
        $node = $body->firstChild;
        while ($node !== null) {
            $isElement = $node->nodeType === XML_ELEMENT_NODE && $node !== $wrapper;
            $name = $isElement ? $this->name($node) : '';
            if ($isElement) {
                $count++;
                $maxDepth = max($maxDepth, $depth);
                if ($project) {
                    $projected += $this->cost($name, $node, $open, $definition, $config);
                }
                if ($stopAtLimit) {
                    if ($this->maxElements !== null && ($count > $this->maxElements || $projected > $this->maxElements)) {
                        return self::result('elements', $maxDepth, $count, $projected);
                    }
                    if ($this->maxDepth !== null && $depth > $this->maxDepth) {
                        return self::result('depth', $maxDepth, $count, $projected);
                    }
                }
            }
            if ($node->firstChild !== null) {
                if ($node !== $wrapper) {
                    $depth++;
                    if ($isElement && ($definition->info[$name]->formatting ?? false)) {
                        $open[$name] = ($open[$name] ?? 0) + 1;
                    }
                }
                $node = $node->firstChild;

                continue;
            }
            while ($node !== null && $node->nextSibling === null) {
                $node = $node->parentNode;
                if ($node === $body) {
                    return self::result(null, $maxDepth, $count, $projected);
                }
                if ($node !== $wrapper) {
                    $depth--;
                    $parentName = $this->name($node);
                    if (($definition->info[$parentName]->formatting ?? false) && isset($open[$parentName])) {
                        $open[$parentName]--;
                    }
                }
            }
            $node = $node?->nextSibling;
        }

        return self::result(null, $maxDepth, $count, $projected);
    }

    /**
     * @param  'depth'|'elements'|null  $violation
     * @return array{violation: 'depth'|'elements'|null, stats: array{depth: int, elements: int, projected: int}}
     */
    private static function result(?string $violation, int $depth, int $elements, int $projected): array
    {
        return ['violation' => $violation, 'stats' => ['depth' => $depth, 'elements' => $elements, 'projected' => $projected]];
    }

    /**
     * @param  array<string, int>  $open
     */
    private function cost(string $name, DOMNode $node, array $open, \HTMLPurifier_HTMLDefinition $definition, HTMLPurifier_Config $config): int
    {
        $cost = 1;
        if (! isset($definition->info[$name])) {
            return $cost;
        }
        $formattingAncestors = 0;
        foreach ($open as $formatting => $n) {
            if ($n > 0) {
                $formattingAncestors += $n;
                if (! isset($this->allowed($formatting, $definition, $config)[$name])) {
                    $cost += 2 * $n;
                }
            }
        }
        // An element its parent does not allow closes ancestors until one allows it. Each ancestor
        // closed early leaves its own end tag behind; that end tag can later match an outer element
        // of the same name and close (and reopen, +1 each) the formatting elements in between,
        // of which there are at most as many as formatting ancestors.
        if ($formattingAncestors > 0) {
            $closed = 0;
            for ($ancestor = $node->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
                $ancestorName = $this->name($ancestor);
                if ($ancestorName === 'body' || ! isset($definition->info[$ancestorName])) {
                    if ($ancestorName === 'body') {
                        break;
                    }

                    continue;
                }
                if (isset($this->allowed($ancestorName, $definition, $config)[$name])) {
                    break;
                }
                $closed++;
            }
            $cost += $closed * $formattingAncestors;
        }
        if ($name === 'ul' || $name === 'ol') {
            $cost += 2;
        } elseif ($name === 'table') {
            $cost += 1;
        } elseif ($name === 'tbody' && $node->parentNode !== null && $this->name($node->parentNode) === 'table') {
            $cost += 1;
        }

        return $cost;
    }

    /** @return array<string, true> */
    private function allowed(string $name, \HTMLPurifier_HTMLDefinition $definition, HTMLPurifier_Config $config): array
    {
        return $this->allowed[$name] ??= $definition->info[$name]->child->getAllowedElements($config);
    }

    /** Lowercase element name, with the link aliases read as `a`. */
    private function name(DOMNode $node): string
    {
        $name = strtolower($node->nodeName);

        return in_array($name, TagScanner::LINK_ALIASES, true) ? 'a' : $name;
    }
}
