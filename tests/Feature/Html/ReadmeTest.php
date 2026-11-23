<?php

use Brume\Html\HtmlSanitizer;

// Keeps the README's rich text whitelist and config table in step with the sanitiser.

function richTextReadme(): string
{
    $readme = file_get_contents(dirname(__DIR__, 3).'/README.md');
    $start = strpos($readme, "\n### Rich text\n");
    expect($start)->not->toBeFalse();

    return substr($readme, $start, strpos($readme, "\n## ", $start + 1) - $start);
}

function readmeBullet(string $section, string $label, string $next): string
{
    $start = strpos($section, "- {$label}:");
    expect($start)->not->toBeFalse();

    return substr($section, $start, strpos($section, "- {$next}:", $start) - $start);
}

it('lists every allowed element in the README', function () {
    $elements = (new ReflectionClassConstant(HtmlSanitizer::class, 'ELEMENTS'))->getValue();
    $bullet = readmeBullet(richTextReadme(), 'Elements', 'Attributes');

    preg_match_all('/`([a-z0-9]+)`/', $bullet, $listed);
    expect($listed[1])->toEqualCanonicalizing($elements);
});

it('names every allowed attribute in the README', function () {
    $attributes = (new ReflectionClassConstant(HtmlSanitizer::class, 'ATTRIBUTES'))->getValue();
    $bullet = readmeBullet(richTextReadme(), 'Attributes', 'Reserved names');

    foreach ($attributes as $attribute) {
        [$element, $name] = explode('.', $attribute);
        expect($bullet)->toMatch('/[`\[|]'.preg_quote($name, '/').'[`\]|]/', $attribute);
        if ($element !== '*') {
            expect($bullet)->toMatch('/\b'.preg_quote($element, '/').'\b/', $attribute);
        }
    }
});

it('documents every brume.html key in the README config table', function () {
    $section = richTextReadme();

    $keys = array_keys((require dirname(__DIR__, 3).'/config/brume.php')['html']);

    expect($keys)->not->toBeEmpty();
    foreach ($keys as $key) {
        expect($section)->toContain("| `{$key}` |");
    }
});
