<?php

namespace Brume\Tests\Support;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\HTMLTemplateElement;
use Dom\Node;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Reduces an HTML fragment to a canonical, line-per-node text so two fragments can be compared
 * at DOM level with a readable diff:
 *
 *  - elements by name, attributes sorted by name; boolean attributes compare by presence only;
 *  - `style` as its declarations in order: property trimmed and lowercased, value with collapsed
 *    whitespace (none after commas or inside parentheses) and `url('x')` / `url("x")` / `url(x)` alike;
 *  - text with ASCII whitespace runs collapsed to one space; U+00A0 and `&nbsp;` are the same
 *    character after parsing and print as `&nbsp;`; comments are dropped.
 *
 * PHP 8.4+ parses with the HTML5 parser (Dom\HTMLDocument); PHP 8.3 falls back to libxml's
 * lenient HTML parser. {@see self::canonical()} with $legacy = true forces the fallback.
 */
final class HtmlNormalizer
{
    private const BOOLEAN_ATTRIBUTES = ['allowfullscreen', 'checked', 'disabled', 'hidden', 'readonly', 'selected'];

    public static function canonical(string $html, bool $legacy = false): string
    {
        $lines = [];
        foreach (self::bodyChildren($html, $legacy) as $node) {
            self::walk($node, 0, $lines);
        }

        return implode("\n", $lines);
    }

    public static function normalizeStyle(string $style): string
    {
        $declarations = [];
        foreach (preg_split('/;(?![^(]*\))/', $style) as $part) {
            if (! str_contains($part, ':')) {
                if (trim($part) !== '') {
                    $declarations[] = trim($part);
                }

                continue;
            }
            [$property, $value] = explode(':', $part, 2);
            $value = preg_replace('/\s+/', ' ', trim($value));
            $value = preg_replace('/\s*,\s*/', ',', $value);
            $value = preg_replace('/\(\s+/', '(', $value);
            $value = preg_replace('/\s+\)/', ')', $value);
            $value = preg_replace('/url\(\s*([\'"])(.*?)\1\s*\)/i', 'url($2)', $value);
            $declarations[] = strtolower(trim($property)).': '.$value;
        }

        return implode('; ', $declarations);
    }

    /** @return iterable<DOMNode|Node> */
    private static function bodyChildren(string $html, bool $legacy): iterable
    {
        if (! $legacy && class_exists(HTMLDocument::class)) {
            $doc = HTMLDocument::createFromString(
                '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
                LIBXML_NOERROR,
            );

            return iterator_to_array($doc->body->childNodes);
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return iterator_to_array($doc->getElementsByTagName('body')->item(0)->childNodes);
    }

    /** @param list<string> $lines */
    private static function walk(DOMNode|Node $node, int $depth, array &$lines): void
    {
        $indent = str_repeat('  ', $depth);
        if ($node instanceof DOMElement || $node instanceof Element) {
            $name = strtolower($node->nodeName);
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $key = strtolower($attribute->nodeName);
                $value = $attribute->nodeValue ?? '';
                if (in_array($key, self::BOOLEAN_ATTRIBUTES, true)) {
                    $value = '';
                } elseif ($key === 'style') {
                    $value = self::normalizeStyle($value);
                    if ($value === '') {
                        continue;
                    }
                }
                $attributes[$key] = $value;
            }
            ksort($attributes);
            $rendered = '';
            foreach ($attributes as $key => $value) {
                $rendered .= ' '.$key.'="'.str_replace('"', '&quot;', $value).'"';
            }
            $lines[] = $indent.'<'.$name.$rendered.'>';
            $children = $node instanceof HTMLTemplateElement ? $node->content->childNodes : $node->childNodes;
            foreach ($children as $child) {
                self::walk($child, $depth + 1, $lines);
            }

            return;
        }
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            $text = preg_replace('/[ \t\n\r\f]+/', ' ', $node->nodeValue ?? '');
            $lines[] = $indent.'"'.str_replace("\u{00A0}", '&nbsp;', $text).'"';
        }
    }
}
