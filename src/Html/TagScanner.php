<?php

namespace Brume\Html;

use Generator;

/**
 * A forward-only, byte-level pass over raw HTML that finds tags without building a tree.
 *
 * Comments, `<!…>` and `<?…>` are skipped; quoted attribute values may contain `>`; the content
 * of `script` and `style` is raw text (as for libxml's parser). Every byte is visited a bounded
 * number of times and the only regex has no quantifier, so the cost is linear even on garbage.
 * An unterminated comment, tag, quote or raw-text element ends the scan: to a parser the rest is
 * not markup either.
 *
 * @internal
 */
final class TagScanner
{
    private const VOID = [
        'area' => true, 'base' => true, 'br' => true, 'col' => true, 'embed' => true, 'hr' => true, 'img' => true,
        'input' => true, 'link' => true, 'meta' => true, 'source' => true, 'track' => true, 'wbr' => true,
    ];

    /** Name used for `a` while libxml parses; mapped back to `a` by a tag transform. */
    public const LINK_ALIAS = 'brumelink';

    /**
     * Element nesting depth: an opening tag that is not void and not self-closed (`<x/>`) adds a
     * level, a closing tag removes one (never below zero), so unclosed tags count. Returns as
     * soon as the depth passes $stopAbove.
     */
    public static function maxDepth(string $html, ?int $stopAbove = null): int
    {
        $depth = 0;
        $max = 0;
        foreach (self::tags($html) as [, , , $name, $closing, $selfClosed]) {
            if ($closing) {
                $depth = $depth > 0 ? $depth - 1 : 0;
            } elseif (! $selfClosed && ! isset(self::VOID[$name]) && ++$depth > $max) {
                $max = $depth;
                if ($stopAbove !== null && $max > $stopAbove) {
                    return $max;
                }
            }
        }

        return $max;
    }

    /**
     * Prepares links for libxml:
     *  - a link inside an open link is invalid HTML: its start and end tags are removed, its
     *    content stays (done here, before the tree exists, so the result is stable on re-cleaning);
     *  - the remaining `a` tags are renamed to {@see self::LINK_ALIAS}. libxml's HTML 4 parser
     *    closes an open `a` before a `<table>` or another `<a>`; an element it does not know keeps
     *    the nesting as written.
     */
    public static function prepareLinks(string $html): string
    {
        $out = '';
        $last = 0;
        /** @var list<bool> $open true = kept, false = removed */
        $open = [];
        foreach (self::tags($html) as [$lt, $gt, $nameStart, $name, $closing, $selfClosed]) {
            // The alias spelt out in the input is a link too, or it would escape the nesting check.
            if ($name !== 'a' && $name !== self::LINK_ALIAS) {
                continue;
            }
            $remove = $closing ? (array_pop($open) === false) : in_array(true, $open, true);
            if (! $closing && ! $selfClosed) {
                $open[] = ! $remove;
            }
            if ($remove) {
                $out .= substr($html, $last, $lt - $last);
                $last = $gt + 1;
            } else {
                $out .= substr($html, $last, $nameStart - $last).self::LINK_ALIAS;
                $last = $nameStart + strlen($name);
            }
        }

        return $last === 0 ? $html : $out.substr($html, $last);
    }

    /**
     * @return Generator<int, array{int, int, int, string, bool, bool}> [offset of `<`, offset of `>`, offset of the name, lowercase name, closing, self-closed]
     */
    private static function tags(string $html): Generator
    {
        $length = strlen($html);
        $pos = 0;
        while (preg_match('/<[a-zA-Z\/!?]/', $html, $match, PREG_OFFSET_CAPTURE, $pos) === 1) {
            $lt = $match[0][1];
            $next = $html[$lt + 1];
            if ($next === '!' || $next === '?') {
                $isComment = $next === '!' && substr_compare($html, '<!--', $lt, 4) === 0;
                $end = $isComment ? strpos($html, '-->', $lt + 4) : strpos($html, '>', $lt + 2);
                if ($end === false) {
                    return;
                }
                $pos = $end + ($isComment ? 3 : 1);

                continue;
            }
            $closing = $next === '/';
            $nameStart = $lt + ($closing ? 2 : 1);
            if ($closing && ! ctype_alpha($html[$nameStart] ?? '')) {
                $pos = $lt + 2;

                continue;
            }
            $nameLength = strcspn($html, " \t\n\r\f/>", $nameStart);
            $name = strtolower(substr($html, $nameStart, $nameLength));

            // The tag ends at the first `>` outside quotes.
            $i = $nameStart + $nameLength;
            while (true) {
                $i += strcspn($html, '>"\'', $i);
                if ($i >= $length) {
                    return;
                }
                if ($html[$i] === '>') {
                    break;
                }
                $close = strpos($html, $html[$i], $i + 1);
                if ($close === false) {
                    return;
                }
                $i = $close + 1;
            }
            $pos = $i + 1;
            $selfClosed = ! $closing && $html[$i - 1] === '/';

            yield [$lt, $i, $nameStart, $name, $closing, $selfClosed];

            if (! $closing && ! $selfClosed && ($name === 'script' || $name === 'style')) {
                if (preg_match('/<\/'.$name.'/i', $html, $end, PREG_OFFSET_CAPTURE, $pos) !== 1) {
                    return;
                }
                $pos = $end[0][1];
            }
        }
    }
}
