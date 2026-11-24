<?php

namespace Brume\Html;

use DOMDocument;
use DOMElement;
use DOMNode;
use HTMLPurifier_Config;
use HTMLPurifier_Context;
use HTMLPurifier_Encoder;
use HTMLPurifier_Lexer_DOMLex;

/**
 * HTMLPurifier's libxml lexer, measuring the tree it builds before tokenising it.
 *
 * Depth, element count and attribute sizes are read from the very DOM that is tokenised (same
 * normalisation and wrapper as {@see HTMLPurifier_Lexer_DOMLex}), so no input can look small to
 * the check and be large to the parser. Over a limit, tokenising stops with {@see HtmlRejected}
 * before HTMLPurifier's own (recursive, memory-hungry) stages run. {@see self::violation()} runs
 * the same parse and walk without tokenising.
 *
 * libxml itself parses, walks and frees 1 000 000 nested levels without crashing; the crashes
 * seen on deep input happen in the stages after the lexer. It does get slow on them, which is
 * why the first parse lets libxml stop at its own depth limit ({@see self::measure()}).
 *
 * Everything that runs on the whole document before the tree exists is linear in its length here:
 * HTMLPurifier's own pre-processing uses lazy regexes that go quadratic on unterminated markers
 * (30 s on 300 KB without PCRE's JIT) and rebuilds the string once per comment, so
 * {@see self::normalize()} and {@see self::manipulateHtmlComments()} replace them with single
 * passes; and libxml checks every attribute of a tag against all earlier ones, so a tag crowded
 * with words is refused before parsing ({@see self::crowdedTag()}).
 *
 * @internal
 */
final class MeasuringLexer extends HTMLPurifier_Lexer_DOMLex
{
    /** Element bytes charged for `<li></li>` and `<tbody></tbody>` that HTMLPurifier may insert. */
    private const LIST_ITEM_BYTES = 9;

    private const TABLE_BODY_BYTES = 15;

    /** The name libxml sees in place of `a` during the current clean (see TagScanner). */
    public string $alias = 'a';

    /** Documents parsed so far; HtmlSanitizer checks it moves, so the limits cannot be bypassed. */
    public int $runs = 0;

    /** Inputs up to this size are parsed without LIBXML_PARSEHUGE first ({@see self::measure()}). */
    private const BOUNDED_PARSE_BYTES = 1_000_000;

    /** libxml said, in the last parse, that it stopped at a limit LIBXML_PARSEHUGE would lift. */
    private bool $cutShort = false;

    /** Words (runs of bytes other than whitespace, `/`, quotes) one tag may hold; null = any. */
    private readonly ?int $maxTagWords;

    /** @var array<string, array<string, true>> allowed children by element name */
    private array $allowed = [];

    /**
     * @param  ?int  $byteBudget  bound on the bytes HTMLPurifier may emit (elements with their attributes, and text)
     */
    public function __construct(
        private readonly ?int $maxDepth,
        private readonly ?int $maxElements,
        private readonly bool $projection = true,
        private readonly ?int $maxAttributeBytes = null,
        private readonly ?int $maxClasses = null,
        private readonly ?int $byteBudget = null,
        private readonly ?int $maxBytes = null,
    ) {
        parent::__construct();
        $this->maxTagWords = $maxAttributeBytes === null ? null : max(256, intdiv($maxAttributeBytes, 8));
    }

    /**
     * Bytes an element can take in the output: both tags, and every attribute with its value
     * escaped. `style`, `href` and `src` values count three times, because validation may rewrite
     * them longer (percent-encoding, colour names to hex, `url(x)` to `url("x")`).
     *
     * @param  iterable<string, string>  $attributes  name => value
     */
    public static function elementBytes(string $name, iterable $attributes): int
    {
        $bytes = 2 * strlen($name) + 5;
        foreach ($attributes as $attribute => $value) {
            $attribute = (string) $attribute;
            $escaped = strlen(htmlspecialchars((string) $value, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8'));
            $growing = $attribute === 'style' || $attribute === 'href' || $attribute === 'src';
            $bytes += 2 * strlen($attribute) + 4 + ($growing ? 3 * $escaped : $escaped);
        }

        return $bytes;
    }

    /**
     * Bytes of the bare element FixNesting may add to this one after MakeWellFormed: an `<li>`
     * around stray list content, a `<tbody>` around rows (one per table, one after each tbody).
     */
    public static function insertedBytes(string $name): int
    {
        return match ($name) {
            'ul', 'ol' => self::LIST_ITEM_BYTES,
            'table', 'tbody' => self::TABLE_BODY_BYTES,
            default => 0,
        };
    }

    /**
     * Depth, element count, projected element count and projected output bytes of the tree,
     * without stopping at limits.
     *
     * @return array{depth: int, elements: int, projected: int, bytes: int}
     */
    public function stats(string $html, HTMLPurifier_Config $config, HTMLPurifier_Context $context): array
    {
        return $this->measure($html, $config, $context, false)[2]['stats'];
    }

    public function violation(string $html, HTMLPurifier_Config $config, HTMLPurifier_Context $context): ?HtmlRejected
    {
        if (! $this->limited()) {
            return null;
        }
        try {
            return $this->measure($html, $config, $context, true)[2]['violation'];
        } catch (HtmlRejected $rejected) {
            return $rejected;
        }
    }

    private function limited(): bool
    {
        return $this->maxDepth !== null || $this->maxElements !== null || $this->maxAttributeBytes !== null
            || $this->maxClasses !== null || $this->byteBudget !== null;
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
        $this->runs++;
        [$body, $div, $measured] = $this->measure($html, $config, $context, true);
        if ($measured['violation'] !== null) {
            throw $measured['violation'];
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
     * Parses and walks, in two steps so that a deep document cannot make the parser itself slow.
     *
     * libxml looks through all open elements for every end tag that closes none of them, so
     * `<i></b>` repeated costs it seconds once tens of thousands of elements are open (1–2 s at
     * 300 KB), long before there is a tree to measure. Without LIBXML_PARSEHUGE it stops by itself
     * at {@see self::nativeDepth()} levels, within a millisecond, and keeps what it built so far:
     * a prefix, in document order, of the full tree. So the first parse is always that one:
     *  - a limit passed in the prefix is passed at the same element of the full tree;
     *  - a tree shallower than libxml's own limit is complete, and is the one tokenised;
     *  - only a tree that reaches libxml's limit without passing max_depth (max_depth null or
     *    above libxml's limit) is parsed again with LIBXML_PARSEHUGE, as slowly as it takes.
     * The tree is taken for cut short when it is as deep as libxml goes, or when libxml mentioned
     * the flag in an error; never on a guess about the input.
     *
     * @return array{DOMElement, DOMElement, array{violation: ?HtmlRejected, stats: array{depth: int, elements: int, projected: int, bytes: int}}}
     */
    private function measure(string $html, HTMLPurifier_Config $config, HTMLPurifier_Context $context, bool $stopAtLimit): array
    {
        $html = $this->prepare($html, $config, $context, $stopAtLimit);
        $deep = $config->get('Core.AllowParseManyTags') && defined('LIBXML_PARSEHUGE');
        // libxml's other limits without the flag (text, comment and name sizes) start at 10 MB;
        // an input anywhere near that only gets the deep parse, as before.
        $bounded = ! $deep || strlen($html) <= self::BOUNDED_PARSE_BYTES;
        [$body, $div] = $this->load($html, $config, ! $bounded);
        $measured = $this->walk($body, $div, $config, $stopAtLimit);
        // Cut short, possibly: as deep as libxml goes, or libxml named the flag in an error.
        if ($bounded && $measured['violation'] === null && ($this->cutShort || $measured['stats']['depth'] >= self::nativeDepth())) {
            if (! $deep) {
                // A configure hook turned the deep parse off: libxml may have dropped the rest.
                if ($stopAtLimit) {
                    throw new HtmlRejected('depth', min($this->maxDepth ?? PHP_INT_MAX, self::nativeDepth() - 1));
                }

                return [$body, $div, $measured];
            }
            [$body, $div] = $this->load($html, $config, true);
            $measured = $this->walk($body, $div, $config, $stopAtLimit);
        }

        return [$body, $div, $measured];
    }

    /**
     * How deep libxml nests elements inside the wrapper without LIBXML_PARSEHUGE, in the units of
     * {@see self::walk()}: 254 with libxml 2.9 (its limit of 256 counts html, body and the wrapper;
     * directly in the body, after a stray `</div>`, one more fits). Probed, since it is libxml's
     * business; PHP_INT_MAX when this libxml has no such limit.
     */
    public static function nativeDepth(): int
    {
        static $depth = null;
        if ($depth === null) {
            $probe = 1000;
            $doc = new DOMDocument;
            set_error_handler(static fn (): bool => true);
            try {
                $doc->loadHTML('<html><body><div>'.str_repeat('<div>', $probe).'</div></body></html>');
            } finally {
                restore_error_handler();
            }
            $depth = -1;
            for ($node = $doc->getElementsByTagName('div')->item(0); $node !== null; $node = $node->firstChild) {
                $depth++;
            }
            $depth = $depth >= $probe ? PHP_INT_MAX : $depth;
        }

        return $depth;
    }

    /**
     * The parent's pre-processing, step for step and with the same results, in linear time: its
     * `<![CDATA[(.+?)]]>`, `(.*?)<body[^>]*>(.*)</body>`, `<\?.+?\?>` and `<script[^>]*>.*?</script>`
     * regexes are quadratic on input that repeats the opening marker and never closes it.
     *
     * @param  string  $html
     * @param  HTMLPurifier_Config  $config
     * @param  HTMLPurifier_Context  $context
     */
    public function normalize($html, $config, $context): string
    {
        $html = (string) $html;
        if ($config->get('Core.NormalizeNewlines')) {
            $html = str_replace("\r", "\n", str_replace("\r\n", "\n", $html));
        }
        $escape = fn (string $inner): string => htmlspecialchars($inner, ENT_COMPAT, 'UTF-8');
        if ($config->get('HTML.Trusted')) {
            $html = self::replaceDelimited($html, '<!--//--><![CDATA[//><!--', '//--><!]]>', $escape);
        }
        $html = self::replaceDelimited($html, '<![CDATA[', ']]>', $escape);

        if ($config->get('Core.ConvertDocumentToFragment')) {
            $body = $this->extractBody($html);
            if ($config->get('Core.CollectErrors') && $body !== $html) {
                $context->get('ErrorCollector')->send(E_WARNING, 'Lexer: Extracted body');
            }
            $html = $body;
        }
        if ($config->get('Core.LegacyEntityDecoder')) {
            $html = $this->_entity_parser->substituteNonSpecialEntities($html);
        }
        $html = HTMLPurifier_Encoder::cleanUTF8($html);
        if ($config->get('Core.RemoveProcessingInstructions')) {
            $html = self::replaceDelimited($html, '<?', '?>', fn (): string => '');
        }
        $hidden = $config->get('Core.HiddenElements');
        if ($config->get('Core.AggressivelyRemoveScript')
            && ! ($config->get('HTML.Trusted') || ! $config->get('Core.RemoveScriptContents') || empty($hidden['script']))) {
            $html = self::removeScripts($html);
        }

        return $html;
    }

    /**
     * `preg_replace_callback('/OPEN(.+?)CLOSE/s', …)` for literal delimiters: each OPEN pairs with
     * the first CLOSE at least one byte after it. When an OPEN has no CLOSE, no later one has.
     *
     * @param  callable(string): string  $replace  receives the content between the delimiters
     */
    private static function replaceDelimited(string $html, string $open, string $close, callable $replace): string
    {
        $out = '';
        $offset = 0;
        $length = strlen($html);
        while (($start = strpos($html, $open, $offset)) !== false) {
            $content = $start + strlen($open);
            if ($content + 1 > $length || ($end = strpos($html, $close, $content + 1)) === false) {
                break;
            }
            $out .= substr($html, $offset, $start - $offset).$replace(substr($html, $content, $end - $content));
            $offset = $end + strlen($close);
        }

        return $offset === 0 ? $html : $out.substr($html, $offset);
    }

    /**
     * The parent's `(.*?)<body[^>]*>(.*)</body>` (case-insensitive, dot matches all): from the end
     * of the first `<body…>` to the last `</body>`, unless that `<body` sits in an open comment.
     *
     * @param  string  $html
     */
    public function extractBody($html): string
    {
        $lower = strtolower($html);
        $open = strpos($lower, '<body');
        $gt = $open === false ? false : strpos($html, '>', $open + 5);
        $close = $gt === false ? false : strrpos($lower, '</body>');
        if ($open === false || $gt === false || $close === false || $close <= $gt) {
            return $html;
        }
        $before = substr($html, 0, $open);
        $commentStart = strrpos($before, '<!--');
        $commentEnd = strrpos($before, '-->');
        if ($commentStart === false || ($commentEnd !== false && $commentEnd > $commentStart)) {
            return substr($html, $gt + 1, $close - $gt - 1);
        }

        return $html;
    }

    /**
     * The parent's `preg_replace('#<script[^>]*>.*?</script>#i', '', …)`: the dot does not match a
     * newline, so a script whose content spans lines stays (its tokens are removed later).
     */
    private static function removeScripts(string $html): string
    {
        $lower = strtolower($html);
        $out = '';
        $copied = 0;
        $search = 0;
        /** @var int|false $close next `</script>` at or after the current tag's end, cached */
        $close = -1;
        /** @var int|false $newline */
        $newline = -1;
        while (($start = strpos($lower, '<script', $search)) !== false) {
            $gt = strpos($html, '>', $start + 7);
            if ($gt === false) {
                break;
            }
            if ($close !== false && $close <= $gt) {
                $close = strpos($lower, '</script>', $gt + 1);
            }
            if ($close === false) {
                break;
            }
            if ($newline !== false && $newline <= $gt) {
                $newline = strpos($html, "\n", $gt + 1);
            }
            if ($newline !== false && $newline < $close) {
                $search = $start + 1;

                continue;
            }
            $out .= substr($html, $copied, $start - $copied);
            $copied = $search = $close + 9;
        }

        return $copied === 0 ? $html : $out.substr($html, $copied);
    }

    /**
     * The parent rebuilds the whole string for every comment; this copies each piece once.
     *
     * @param  string  $html
     */
    protected function manipulateHtmlComments($html, callable $callback): string
    {
        return self::replaceComments((string) $html, $callback);
    }

    /** @param  callable(string): string  $callback */
    private static function replaceComments(string $html, callable $callback): string
    {
        $out = '';
        $offset = 0;
        while (($start = strpos($html, '<!--', $offset)) !== false) {
            $end = strpos($html, '-->', $start + 4);
            if ($end === false) {
                break;
            }
            $out .= substr($html, $offset, $start - $offset).'<!--'.$callback(substr($html, $start + 4, $end - $start - 4)).'-->';
            $offset = $end + 3;
        }

        return $offset === 0 ? $html : $out.substr($html, $offset);
    }

    /**
     * True when some tag could hold more than $max attributes. libxml compares each attribute of
     * a tag with all the earlier ones, so tens of thousands of them cost seconds inside the
     * parser, before any tree exists to measure.
     *
     * No tag boundaries are guessed. The string is cut into spans, each from a `<` to the next
     * `>` that cannot be inside a quoted attribute value: one where neither the nearest `"` nor
     * the nearest `'` before it follows an `=`. A parser may end a tag earlier than that, never
     * later, so every start tag lies within one span, and it has at most as many attributes as
     * the span has words (attributes are separated by whitespace, `/` or a closing quote).
     */
    private static function crowdedTag(string $html, int $max): bool
    {
        $length = strlen($html);
        $pos = 0;
        while ($pos < $length && ($lt = strpos($html, '<', $pos)) !== false) {
            $quoted = ['"' => false, "'" => false];
            $i = $lt + 1;
            while (true) {
                $i += strcspn($html, '>"\'', $i);
                if ($i >= $length) {
                    break;
                }
                $char = $html[$i];
                if ($char === '>') {
                    if (! $quoted['"'] && ! $quoted["'"]) {
                        break;
                    }
                } else {
                    $before = $i - 1;
                    while ($before > $lt && strpos(" \t\n\r\f", $html[$before]) !== false) {
                        $before--;
                    }
                    $quoted[$char] = $html[$before] === '=';
                }
                $i++;
            }
            if ($i - $lt > 2 * $max && preg_match_all('/[^ \t\n\r\f\/"\'>]+/', substr($html, $lt, $i - $lt)) > $max) {
                return true;
            }
            $pos = $i + 1;
        }

        return false;
    }

    /**
     * The parent's steps before parsing, verbatim, plus the crowded-tag check on the string
     * libxml gets.
     */
    private function prepare(string $html, HTMLPurifier_Config $config, HTMLPurifier_Context $context, bool $check): string
    {
        $html = $this->normalize($html, $config, $context);
        if ($config->get('Core.AggressivelyFixLt')) {
            $html = $this->aggressivelyFixLt($html);
        }
        $html = $this->wrapHTML($html, $config, $context);
        if ($check && $this->maxTagWords !== null && self::crowdedTag($html, $this->maxTagWords)) {
            throw new HtmlRejected('attributes', $this->maxTagWords);
        }

        return $html;
    }

    /**
     * The parent's parsing step, with LIBXML_PARSEHUGE only when asked ({@see self::measure()}).
     *
     * @return array{DOMElement, DOMElement} body and wrapper
     */
    private function load(string $html, HTMLPurifier_Config $config, bool $huge): array
    {
        $doc = new DOMDocument;
        $doc->encoding = 'UTF-8';
        $options = 0;
        if ($huge) {
            $options |= LIBXML_PARSEHUGE;
        }
        if ($config->get('Core.RemoveBlanks') && defined('LIBXML_NOBLANKS')) {
            $options |= LIBXML_NOBLANKS;
        }
        $this->cutShort = false;
        // Mutes libxml's warnings like the parent does, and notes the ones about a limit that
        // LIBXML_PARSEHUGE lifts ("Excessive depth in document: 256 use XML_PARSE_HUGE option").
        set_error_handler(function (int $level, string $message): bool {
            $this->cutShort = $this->cutShort || str_contains($message, '_HUGE');

            return true;
        });
        try {
            $options ? $doc->loadHTML($html, $options) : $doc->loadHTML($html);
        } finally {
            restore_error_handler();
        }

        $body = $doc->getElementsByTagName('html')->item(0)->getElementsByTagName('body')->item(0);
        $div = $body->getElementsByTagName('div')->item(0);

        return [$body, $div];
    }

    /**
     * Iterative walk (no recursion) of the body; the wrapper div is neither counted nor a level.
     *
     * Per element it checks the attribute caps: no value longer than max_attribute_bytes, no more
     * than max_classes classes, no more attributes than a tag may hold words.
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
     * Every element counts at least 1, known to the definition or not.
     *
     * "bytes" bounds the size of what HTMLPurifier will emit, the same way: every copy above is
     * charged the bytes of the element it copies ({@see self::elementBytes()}), attributes
     * included, since each copy is validated and serialised separately; text is charged escaped.
     * ElementBudgetInjector enforces both again at runtime, so an error here cannot exhaust memory.
     *
     * @param  bool  $stopAtLimit  return as soon as a limit is passed
     * @return array{violation: ?HtmlRejected, stats: array{depth: int, elements: int, projected: int, bytes: int}}
     */
    private function walk(DOMElement $body, DOMElement $wrapper, HTMLPurifier_Config $config, bool $stopAtLimit): array
    {
        $definition = $config->getHTMLDefinition();
        $project = $this->projection || ! $stopAtLimit;
        $count = 0;
        $projected = 0;
        $bytes = 0;
        $depth = 1;
        $maxDepth = 0;
        /** @var array<string, array{int, int}> $open open formatting ancestors by name: how many, and their bytes */
        $open = [];
        /** @var list<int> $openBytes bytes of each open formatting ancestor, innermost last */
        $openBytes = [];
        $openTotal = 0;
        /** @var list<string> $known names of the open ancestors the definition knows (and the wrapper div), innermost last */
        $known = [];
        $stats = function () use (&$maxDepth, &$count, &$projected, &$bytes): array {
            return ['depth' => $maxDepth, 'elements' => $count, 'projected' => $projected, 'bytes' => $bytes];
        };
        $reject = fn (string $reason, ?int $limit): array => ['violation' => new HtmlRejected($reason, (int) $limit), 'stats' => $stats()];

        /** @var DOMNode|null $node */
        $node = $body->firstChild;
        while ($node !== null) {
            $isElement = $node->nodeType === XML_ELEMENT_NODE && $node !== $wrapper;
            $name = $isElement ? $this->name($node) : '';
            $own = 0;
            if ($isElement) {
                $count++;
                $maxDepth = max($maxDepth, $depth);
                $attributes = [];
                if ($node->hasAttributes()) {
                    if ($stopAtLimit && $this->maxTagWords !== null && $node->attributes->length > $this->maxTagWords) {
                        return $reject('attributes', $this->maxTagWords);
                    }
                    foreach ($node->attributes as $attribute) {
                        $attributes[$attribute->name] = $value = $attribute->value;
                        if (! $stopAtLimit) {
                            continue;
                        }
                        if ($this->maxAttributeBytes !== null && strlen($value) > $this->maxAttributeBytes) {
                            return $reject('attributes', $this->maxAttributeBytes);
                        }
                        // More than n classes take at least 2n + 1 bytes.
                        if ($this->maxClasses !== null && $attribute->name === 'class' && strlen($value) > 2 * $this->maxClasses
                            && count(preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY)) > $this->maxClasses) {
                            return $reject('attributes', $this->maxClasses);
                        }
                    }
                }
                $own = self::elementBytes($name, $attributes);
                $bytes += $own;
                $projected++;
                if ($project && isset($definition->info[$name])) {
                    [$extra, $extraBytes] = $this->cost($name, $node, $open, $openTotal, $known, $definition, $config);
                    $projected += $extra;
                    $bytes += $extraBytes;
                }
                if ($stopAtLimit) {
                    if ($this->maxElements !== null && ($count > $this->maxElements || $projected > $this->maxElements)) {
                        return $reject('elements', $this->maxElements);
                    }
                    if ($this->maxDepth !== null && $depth > $this->maxDepth) {
                        return $reject('depth', $this->maxDepth);
                    }
                }
            } elseif ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                $bytes += strlen(htmlspecialchars((string) $node->nodeValue, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            }
            if ($stopAtLimit && $this->byteBudget !== null && $bytes > $this->byteBudget) {
                return $reject('bytes', $this->maxBytes);
            }
            if ($node->firstChild !== null) {
                if ($node === $wrapper) {
                    $known[] = 'div';
                } else {
                    $depth++;
                    if ($isElement && isset($definition->info[$name])) {
                        $known[] = $name;
                    }
                    if ($isElement && ($definition->info[$name]->formatting ?? false)) {
                        $open[$name] = [($open[$name][0] ?? 0) + 1, ($open[$name][1] ?? 0) + $own];
                        $openBytes[] = $own;
                        $openTotal += $own;
                    }
                }
                $node = $node->firstChild;

                continue;
            }
            while ($node !== null && $node->nextSibling === null) {
                $node = $node->parentNode;
                if ($node === $body) {
                    return ['violation' => null, 'stats' => $stats()];
                }
                if ($node === $wrapper) {
                    array_pop($known);
                } else {
                    $depth--;
                    $parentName = $this->name($node);
                    if (isset($definition->info[$parentName])) {
                        array_pop($known);
                    }
                    if (($definition->info[$parentName]->formatting ?? false) && ($open[$parentName][0] ?? 0) > 0) {
                        $closed = array_pop($openBytes);
                        $openTotal -= $closed;
                        $open[$parentName] = [$open[$parentName][0] - 1, $open[$parentName][1] - $closed];
                    }
                }
            }
            $node = $node?->nextSibling;
        }

        return ['violation' => null, 'stats' => $stats()];
    }

    /**
     * Elements, and their bytes, that HTMLPurifier may emit because of this element, beyond itself.
     *
     * @param  array<string, array{int, int}>  $open
     * @param  int  $openTotal  bytes of all open formatting ancestors
     * @param  list<string>  $known  open ancestors the definition knows, innermost last
     * @return array{int, int}
     */
    private function cost(string $name, DOMNode $node, array $open, int $openTotal, array $known, \HTMLPurifier_HTMLDefinition $definition, HTMLPurifier_Config $config): array
    {
        $cost = 0;
        $bytes = 0;
        $formattingAncestors = 0;
        foreach ($open as $formatting => [$n, $formattingBytes]) {
            if ($n > 0) {
                $formattingAncestors += $n;
                if (! isset($this->allowed($formatting, $definition, $config)[$name])) {
                    $cost += 2 * $n;
                    $bytes += 2 * $formattingBytes;
                }
            }
        }
        // An element its parent does not allow closes ancestors until one allows it. Each ancestor
        // closed early leaves its own end tag behind; that end tag can later match an outer element
        // of the same name and close (and reopen, +1 each) the formatting elements in between,
        // of which there are at most as many as formatting ancestors.
        if ($formattingAncestors > 0) {
            $closed = 0;
            // Only known ancestors are visited, so the loop runs closed + 1 times and every
            // round but the last is charged below: the walk stays linear in what it charges.
            for ($i = count($known) - 1; $i >= 0; $i--) {
                if (isset($this->allowed($known[$i], $definition, $config)[$name])) {
                    break;
                }
                $closed++;
            }
            $cost += $closed * $formattingAncestors;
            $bytes += $closed * $openTotal;
        }
        if ($name === 'ul' || $name === 'ol') {
            $cost += 2;
            $bytes += 2 * self::LIST_ITEM_BYTES;
        } elseif ($name === 'table') {
            $cost += 1;
            $bytes += self::TABLE_BODY_BYTES;
        } elseif ($name === 'tbody' && $node->parentNode !== null && $this->name($node->parentNode) === 'table') {
            $cost += 1;
            $bytes += self::TABLE_BODY_BYTES;
        }

        return [$cost, $bytes];
    }

    /** @return array<string, true> */
    private function allowed(string $name, \HTMLPurifier_HTMLDefinition $definition, HTMLPurifier_Config $config): array
    {
        return $this->allowed[$name] ??= $definition->info[$name]->child->getAllowedElements($config);
    }

    /** Lowercase element name, with the link alias read as `a`. */
    private function name(DOMNode $node): string
    {
        $name = strtolower($node->nodeName);

        return $name === $this->alias ? 'a' : $name;
    }
}
