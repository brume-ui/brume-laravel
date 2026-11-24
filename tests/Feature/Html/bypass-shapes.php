<?php

/**
 * Inputs on which a string scanner and libxml disagree about tag boundaries (fix round 2).
 * Shared by HtmlSanitizerTest and the child process it starts.
 */
function bypassShape(string $kind, int $n): string
{
    return match ($kind) {
        // the scanner pairs the quote in a'b with the one in '>; libxml does not
        'quote' => "<b x=a'b>".str_repeat('<b>', $n)."'>x",
        // NUL bytes are removed before parsing
        'nul' => str_repeat("<\0b>", $n).'x',
        // looks like a processing instruction to a scanner
        'pi' => str_repeat('<?x <b a=">">', $n).'x',
        'plain' => str_repeat('<b>', $n).'x',
    };
}

/**
 * Fix round 3: K formatting tags (b, i, u, s, em, strong, sup, sub, cycling), then M copies of
 * $block. HTMLPurifier reopens every open formatting element inside each block.
 */
function amp(int $k, int $m, string $block = '<div>x</div>'): string
{
    $formatting = ['b', 'i', 'u', 's', 'em', 'strong', 'sup', 'sub'];
    $html = '';
    for ($i = 0; $i < $k; $i++) {
        $html .= '<'.$formatting[$i % 8].'>';
    }

    return $html.str_repeat($block, $m);
}

/**
 * Fix round 4: one formatting element carrying a large attribute, then $blocks blocks.
 * HTMLPurifier reopens the element, attribute included, inside and after every block.
 */
function attributeFlood(string $attribute, string $value, int $blocks, string $formatting = 'b'): string
{
    return '<'.$formatting.' '.$attribute.'="'.$value.'">'.str_repeat('<div>x</div>', $blocks);
}

/** Number of elements in sanitiser output (well-formed, lowercase tags). */
function outputElements(?string $html): int
{
    return $html === null ? 0 : preg_match_all('/<[a-z]/', $html);
}
