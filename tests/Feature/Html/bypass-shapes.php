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
