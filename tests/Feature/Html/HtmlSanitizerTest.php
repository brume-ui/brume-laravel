<?php

use Brume\Html\HtmlRejected;
use Brume\Html\HtmlSanitizer;
use Brume\Html\MeasuringLexer;
use Brume\Html\TagScanner;
use Brume\Tests\Support\HtmlNormalizer;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/bypass-shapes.php';

const HTML_FIXTURES = __DIR__.'/../../fixtures/html';

/**
 * What a fixture is expected to lose, as literal edits applied to the input before comparing.
 * Everything else must come back DOM-equivalent.
 */
const HTML_FIXTURE_LOSSES = [
    // A link inside a link is invalid HTML: the inner link is unwrapped, its text kept.
    '13-links-around-blocks.html' => ['<a href="/demo/shop/gift-box" class="innerLink">禮盒包裝</a>' => '禮盒包裝'],
    // Misspelt properties: browsers ignore them, the sanitiser drops them.
    '11-invalid-css.html' => [
        ' style="colr: #4f7a4a; text-algin: center;"' => '',
        ' margin-botom: 10px;' => '',
        ' font-weigth: bold;' => '',
        ' heigth: 200px;' => '',
    ],
];

function htmlFixture(string $name): string
{
    return file_get_contents(HTML_FIXTURES.'/'.$name);
}

/** @return list<string> */
function legacyHtmlFixtures(): array
{
    return array_values(array_filter(
        array_map('basename', glob(HTML_FIXTURES.'/*.html')),
        fn (string $name) => $name !== 'hostile.html',
    ));
}

function sanitizer(array $options = []): HtmlSanitizer
{
    return new HtmlSanitizer(['cache_path' => false, ...$options]);
}

/** Parses sanitiser output; returns every element as [name, attributes]. */
function htmlElements(string $html): array
{
    $doc = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $elements = [];
    foreach ($doc->getElementsByTagName('body')->item(0)->getElementsByTagName('*') as $element) {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[$attribute->nodeName] = $attribute->nodeValue;
        }
        $elements[] = [$element->nodeName, $attributes];
    }

    return $elements;
}

function tempDir(string $prefix): string
{
    $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    return $dir;
}

function throwingErrorHandler(): void
{
    error_reporting(E_ALL);
    // Like Laravel's HandleExceptions: every reported level throws; @-silenced calls do not.
    set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) {
        if (! (error_reporting() & $level)) {
            return false;
        }
        throw new ErrorException($message, 0, $level, $file, $line);
    });
}

/**
 * Cleans the HTML built by $expression (PHP code using bypass-shapes.php) in a child process
 * with its own memory_limit, so a fatal error or crash cannot take the suite down. Returns
 * [exit code, 'accepted'|'rejected:<reason>', milliseconds, extra MB, output elements].
 *
 * @return array{int, string, float, float, int}
 */
function cleanInChild(string $expression, array $options = [], string $memoryLimit = '128M'): array
{
    $code = 'require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).';'
        .'require '.var_export(__DIR__.'/bypass-shapes.php', true).';'
        .'$s = new Brume\Html\HtmlSanitizer('.var_export(['cache_path' => false, ...$options], true).');'
        .'$s->clean("<p><b>warm</b></p>"); $h = '.$expression.'; gc_collect_cycles(); memory_reset_peak_usage();'
        .'$m = memory_get_usage(); $t = hrtime(true); $n = 0;'
        .'try { $n = outputElements($s->clean($h)); $r = "accepted"; } catch (Brume\Html\HtmlRejected $e) { $r = "rejected:".$e->reason; }'
        .'printf("%s %.1f %.1f %d", $r, (hrtime(true) - $t) / 1e6, (memory_get_peak_usage() - $m) / 1048576, $n);';
    exec(escapeshellarg(PHP_BINARY).' -d memory_limit='.$memoryLimit.' -r '.escapeshellarg($code).' 2>&1', $lines, $exit);
    $parts = explode(' ', $lines[0] ?? '');
    // The time targets are for a developer machine; shared CI runners measured 2-3 times slower.
    $slack = getenv('CI') ? 3 : 1;

    return [$exit, $parts[0] ?? implode("\n", $lines), (float) ($parts[1] ?? 0) / $slack, (float) ($parts[2] ?? 0), (int) ($parts[3] ?? 0)];
}

dataset('legacy fixtures', fn () => legacyHtmlFixtures());

it('keeps preformatted blocks and inline code, with their whitespace and without event attributes', function () {
    $html = "<pre>line1\n\n  line2</pre><p><code>x = 1</code> <code onclick=\"a()\">y</code></p>";

    expect((new HtmlSanitizer(['cache_path' => false]))->clean($html))
        ->toBe("<pre>line1\n\n  line2</pre><p><code>x = 1</code> <code>y</code></p>");
});

it('treats a lone vertical tab as empty but not byte 0x85', function () {
    expect(HtmlSanitizer::isEmpty("\x0B"))->toBeTrue()
        ->and(HtmlSanitizer::isEmpty("\x85"))->toBeFalse();
});

it('ships the hand-written fixtures (twelve from brume-ui, links around blocks, table caption and footer) and the hostile one', function () {
    expect(legacyHtmlFixtures())->toHaveCount(14)
        ->and(file_exists(HTML_FIXTURES.'/hostile.html'))->toBeTrue();
});

it('keeps a legacy fixture DOM-equivalent', function (string $name) {
    $expected = htmlFixture($name);
    foreach (HTML_FIXTURE_LOSSES[$name] ?? [] as $search => $replace) {
        expect($expected)->toContain($search);
        $expected = str_replace($search, $replace, $expected);
    }

    $clean = sanitizer()->clean(htmlFixture($name));

    expect(HtmlNormalizer::canonical($clean))->toBe(HtmlNormalizer::canonical($expected));
})->with('legacy fixtures');

it('serialises void elements HTML5-style and keeps b, i and rel exactly as written', function (string $name) {
    $input = htmlFixture($name);
    $clean = sanitizer()->clean($input);

    expect($clean)->not->toContain('/>')
        ->and(substr_count($clean, '<b>'))->toBe(substr_count($input, '<b>'))
        ->and(substr_count($clean, '<i>'))->toBe(substr_count($input, '<i>'))
        ->and(substr_count($clean, 'rel='))->toBe(substr_count($input, 'rel='))
        ->and(substr_count($clean, '<br>'))->toBe(substr_count($input, '<br>'));
})->with('legacy fixtures');

it('is idempotent', function (string $name) {
    $once = sanitizer()->clean(htmlFixture($name));

    expect(sanitizer()->clean($once))->toBe($once);
})->with([...legacyHtmlFixtures(), 'hostile.html']);

it('reaches a fixed point in one pass', function (string $html, ?string $expected) {
    $once = sanitizer()->clean($html);

    expect($once)->toBe($expected)
        ->and(sanitizer()->clean((string) $once))->toBe($once);
})->with([
    'row left with whitespace only' => ['<table><tr>><t> ', null],
    'row with stray text and a cell' => ['<table><tr>z <td>c</td></tr></table>', '<table><tr><td>c</td></tr></table>'],
    'body left with whitespace only' => ['<table><tbody>z </tbody></table>', null],
    'stray text in a list' => ['<ul>>x </ul>', '<ul><li>&gt;x </li></ul>'],
]);

it('never rewrites text the author wrote, whatever names it contains', function (string $html) {
    expect(sanitizer()->clean($html))->toBe($html);
})->with([
    '<p>&lt;brumelink href="/x"&gt; and BrumeLink are words</p>',
    '<p>&lt;/BRUMELINK&gt;</p><p><a href="/y">link</a></p>',
    // Fix round 4: with all three former alias names present, `&lt;brumelink x&gt;` became `&lt;a x&gt;`.
    '<p>&lt;brumelink x&gt; brumeanchor brumehref <a href="/y">l</a></p>',
    '<p>brumea &lt;brumea0123456789abcdef01234567 x&gt; <a href="/y">l</a></p>',
]);

it('derives the link alias from the input, so the input can never contain it', function () {
    $inputs = ['', '<a href="/x">y</a>', 'brumelink brumeanchor brumehref', str_repeat('<a>brumea', 500)];
    foreach ($inputs as $html) {
        $alias = TagScanner::aliasFor($html);

        expect($alias)->toMatch('/^brumea[0-9a-f]{24}\z/')
            ->and(stripos($html, $alias))->toBeFalse()
            ->and(TagScanner::aliasFor($html))->toBe($alias);
    }
    // An input that spells out the alias of another input simply gets a different one.
    $first = TagScanner::aliasFor('<a>x</a>');
    $spelt = '<a>x</a><'.$first.' href="/x">y</'.$first.'>';

    expect(TagScanner::aliasFor($spelt))->not->toBe($first)
        ->and(sanitizer()->clean($spelt))->toBe('<a>x</a>y');
});

it('never lets the link alias show, even as text', function (string $html, string $expected) {
    expect(sanitizer()->clean($html))->toBe($expected);
})->with([
    // libxml reads a CDATA section as text; TagScanner had renamed the tags inside it.
    'link tags inside CDATA' => ['<![CDATA[<a href="/x">t</a>]]><p>ok</p>', '&lt;a href="/x"&gt;t&lt;/a&gt;<p>ok</p>'],
    'closing tag inside CDATA' => ['<p>x<![CDATA[</A >]]></p>', '<p>x&lt;/A &gt;</p>'],
]);

it('normalises fixtures the same with the PHP 8.3 fallback parser', function (string $name) {
    if (! class_exists(HTMLDocument::class)) {
        $this->markTestSkipped('Dom\HTMLDocument needs PHP 8.4+.');
    }
    $html = htmlFixture($name);

    expect(HtmlNormalizer::canonical($html, legacy: true))->toBe(HtmlNormalizer::canonical($html));
})->with(array_diff(legacyHtmlFixtures(), [
    // libxml's HTML 4 parser closes a link before a <table> and before a nested link, so on
    // PHP 8.3 both sides of that fixture's comparison are parsed the same lossy way (see below).
    '13-links-around-blocks.html',
]));

it('keeps a legacy fixture DOM-equivalent under the PHP 8.3 fallback parser too', function (string $name) {
    $expected = htmlFixture($name);
    foreach (HTML_FIXTURE_LOSSES[$name] ?? [] as $search => $replace) {
        $expected = str_replace($search, $replace, $expected);
    }

    $clean = sanitizer()->clean(htmlFixture($name));

    expect(HtmlNormalizer::canonical($clean, legacy: true))->toBe(HtmlNormalizer::canonical($expected, legacy: true));
})->with('legacy fixtures');

it('normalises style declarations but not their order', function () {
    expect(HtmlNormalizer::normalizeStyle("COLOR : red;background-image: url('/a.png') ; box-shadow: 0 1px rgba( 0, 0, 0, 0.1 )"))
        ->toBe('color: red; background-image: url(/a.png); box-shadow: 0 1px rgba(0,0,0,0.1)')
        ->and(HtmlNormalizer::canonical('<p style="color:red;margin:0">a&nbsp;b</p>'))
        ->not->toBe(HtmlNormalizer::canonical('<p style="margin:0;color:red">a b</p>'));
});

describe('hostile input', function () {
    $allowed = [
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [], 'p' => [], 'ul' => [], 'ol' => ['start', 'type'], 'li' => [],
        'table' => ['border', 'cellpadding', 'cellspacing', 'width'], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan', 'data-th'], 'colgroup' => [], 'col' => ['span', 'width'], 'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'a' => ['href', 'target', 'rel', 'title'], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'sup' => [], 'sub' => [],
        'span' => [], 'div' => [], 'article' => [], 'blockquote' => [], 'hr' => [],
        'iframe' => ['src', 'width', 'height', 'allowfullscreen', 'frameborder', 'title', 'loading'],
        'input' => ['type', 'name', 'checked'], 'label' => ['for'],
    ];

    it('leaves only whitelisted elements and attributes', function () use ($allowed) {
        $clean = sanitizer()->clean(htmlFixture('hostile.html'));

        foreach (htmlElements($clean) as [$name, $attributes]) {
            expect($allowed)->toHaveKey($name);
            foreach (array_keys($attributes) as $attribute) {
                expect([...$allowed[$name], 'class', 'style', 'id'])->toContain($attribute);
            }
        }
    });

    it('keeps the legitimate siblings around every hostile part', function () {
        $clean = sanitizer()->clean(htmlFixture('hostile.html'));

        foreach (range(1, 32) as $n) {
            expect($clean)->toContain(sprintf('keep-%02d', $n));
        }
        expect($clean)->toContain('<img src="/ok/a.png" alt="ok-image">')
            ->toContain('<input type="checkbox" id="acc9" checked>')
            ->toContain('<label for="acc9">accordion-label</label>')
            ->toContain('<iframe src="https://www.youtube-nocookie.com/embed/legitVideo1" width="560" height="314" frameborder="0" allowfullscreen></iframe>')
            ->toContain('<a href="/relative/ok" target="_blank" rel="noopener">relative-link</a>')
            ->toContain('<a href="https://example.com/ok">https-link</a>')
            ->toContain('<a href="mailto:shop@example.com">mail-link</a>')
            // HTMLPurifier's tel scheme keeps digits, x, commas and a leading + only.
            ->toContain('<a href="tel:+886212345678">tel-link</a>')
            ->toContain('<p id="safe-id">safe-id-kept</p>')
            ->toContain('color:#FF0000;">bg-js')
            ->toContain('color:#0000FF;">bg-image-js')
            ->toContain('color:#008000;">bg-data')
            ->toContain('color:#123456;">expr')
            ->toContain('color:#654321;">binding')
            ->toContain('color:#fedcba;">z-index')
            ->toContain('>fixed-overlay</div>');
    });

    it('removes scripts, handlers, dangerous schemes and frames', function () {
        $clean = sanitizer()->clean(htmlFixture('hostile.html'));
        $elements = htmlElements($clean);

        $iframes = array_values(array_filter($elements, fn ($e) => $e[0] === 'iframe'));
        expect($iframes)->toHaveCount(1)
            ->and($iframes[0][1]['src'])->toBe('https://www.youtube-nocookie.com/embed/legitVideo1');

        foreach ($elements as [$name, $attributes]) {
            foreach ($attributes as $attribute => $value) {
                expect($attribute)->not->toStartWith('on');
                if (in_array($attribute, ['href', 'src'], true)) {
                    // Relative (same-site) or http/https; mailto/tel only on links.
                    $scheme = parse_url($value, PHP_URL_SCHEME);
                    expect($scheme)->toBeIn($name === 'a' ? [null, 'http', 'https', 'mailto', 'tel'] : [null, 'http', 'https']);
                }
                if ($attribute === 'style') {
                    expect(strtolower($value))->not->toContain('fixed')
                        ->not->toContain('url(')
                        ->not->toContain('expression')
                        ->not->toContain('behavior')
                        ->not->toContain('binding')
                        ->not->toContain('import')
                        ->not->toContain('99999999');
                }
                if ($attribute === 'id') {
                    expect($value)->not->toBeIn(['document', '__proto__', 'location']);
                }
            }
        }
        expect(strtolower($clean))->not->toContain('<script')
            ->not->toContain('<style')
            ->not->toContain('<svg')
            ->not->toContain('<math')
            ->not->toContain('<noscript')
            ->not->toContain('<template')
            ->not->toContain('srcdoc')
            ->not->toContain('<base')
            ->not->toContain('<meta')
            ->not->toContain('<form')
            ->not->toContain('<button')
            ->not->toContain('<textarea')
            ->not->toContain('<select')
            ->not->toContain('<object')
            ->not->toContain('<embed')
            ->not->toContain('<link')
            ->not->toContain('type="text"')
            ->not->toContain('type="hidden"')
            ->not->toContain('type="password"')
            ->not->toContain('javascript:')
            ->not->toContain('vbscript:')
            ->not->toContain('data:');
    });

    it('rejects data: images: pictures must be uploaded', function () {
        expect(sanitizer()->clean('<p>a<img src="data:image/png;base64,iVBORw0KGgo=" alt="x"></p>'))->toBe('<p>a</p>');
    });

    it('rejects 500 nested divs by default, and with max_depth null keeps everything', function () {
        $html = '<p>before</p>'.str_repeat('<div>', 500).'deep<script>alert(1)</script>'.str_repeat('</div>', 500).'<p>after</p>';
        $start = microtime(true);

        expect(fn () => sanitizer()->clean($html))->toThrow(HtmlRejected::class);
        $clean = sanitizer(['max_depth' => null])->clean($html);

        expect(microtime(true) - $start)->toBeLessThan(5.0)
            ->and($clean)->toContain('<p>before</p>')
            ->toContain('deep')
            ->toContain('<p>after</p>')
            ->not->toContain('script')
            ->and(substr_count($clean, '<div>'))->toBe(500);
    });

    it('replaces invalid UTF-8 with U+FFFD before parsing, keeping the structure', function () {
        throwingErrorHandler();
        try {
            $clean = sanitizer()->clean("<p>ok\xC3\x28 \xFF\xFE bytes</p><p>中文</p>");
            $attributes = sanitizer()->clean("<p class=\"\xC0\" title=\"\xff\">a</p><p class=\"x\">b</p>");
        } finally {
            restore_error_handler();
        }

        expect($clean)->toBe("<p>ok\u{FFFD}( \u{FFFD}\u{FFFD} bytes</p><p>中文</p>")
            ->and($attributes)->toBe("<p class=\"\u{FFFD}\">a</p><p class=\"x\">b</p>")
            ->and(mb_check_encoding($clean, 'UTF-8'))->toBeTrue();
    });

    it('strips NUL bytes', function () {
        expect(sanitizer()->clean("<p>a\0b</p><img src=\"/a\0.png\" alt=\"\0x\">"))
            ->toBe('<p>ab</p><img src="/a.png" alt="x">');
    });
});

describe('legacy content gains nothing', function () {
    it('does not add alt, rel, border, summary or default attributes', function (string $html) {
        expect(sanitizer()->clean($html))->toBe($html);
    })->with([
        '<p><img src="/a.png"></p>',
        '<p><img src="https://cdn.example.com/a.jpg" width="2400" height="1600" style="width:2400px;"></p>',
        '<p><a href="/x" target="_blank">x</a></p>',
        '<p><a href="/x" target="_blank" rel="noopener noreferrer">x</a></p>',
        '<table><tbody><tr><td>x</td></tr></tbody></table>',
        '<table border="1"><colgroup><col></colgroup><tbody><tr><th>h</th></tr></tbody></table>',
        '<p><input type="checkbox"><input type="radio" name="g" checked></p>',
        '<p>a<br>b</p><hr>',
        '<p id="02">numeric id</p>',
        '<ol start="3" type="a"><li>x</li></ol>',
        '<a href="/x"><div class="card"><p>block link</p></div></a>',
        '<article class="post"><p>x</p></article>',
        '<div style="background-position:center center;">x</div>',
        '<div style="position:sticky;top:calc(100% - 10px);z-index:9999;">x</div>',
        '<div style="display:flex;flex:1 1 0%;gap:clamp(8px, 2vw, 16px);">x</div>',
        '<div style="z-index:-9999;">x</div>',
        '<p><iframe src="//www.youtube.com/embed/dQw4w9WgXcQ" title="video" loading="lazy"></iframe></p>',
    ]);

    it('keeps the value but drops !important, like the stock properties', function () {
        expect(sanitizer()->clean('<div style="display:flex !important;color:#123456 !important;">x</div>'))
            ->toBe('<div style="display:flex;color:#123456;">x</div>');
    });

    it('spells named colours as hex (HTMLPurifier behaviour, same rendering)', function () {
        expect(sanitizer()->clean('<p style="color:red;background-color:White;">x</p>'))
            ->toBe('<p style="color:#FF0000;background-color:#FFFFFF;">x</p>');
    });

    it('drops z-index above 9999 and unknown positions', function () {
        expect(sanitizer()->clean('<div style="z-index:10000;position:fixed;color:#123456;">x</div>'))
            ->toBe('<div style="color:#123456;">x</div>');
    });

    it('requires an input type of checkbox or radio', function () {
        expect(sanitizer()->clean('<p><input name="a"><input type="text"><input type="RADIO">x</p>'))
            ->toBe('<p><input type="radio">x</p>');
    });

    it('removes an iframe whose src was missing or rejected, shell included', function () {
        expect(sanitizer()->clean('<p>a<iframe width="1"></iframe><iframe src="https://evil.example.com/embed/x"></iframe>b</p>'))
            ->toBe('<p>ab</p>');
    });
});

describe('options', function () {
    it('keeps position:fixed only with allow_fixed', function () {
        $html = '<div style="position:fixed;top:0;">x</div>';

        expect(sanitizer()->clean($html))->toBe('<div style="top:0;">x</div>')
            ->and(sanitizer(['allow_fixed' => true])->clean($html))->toBe($html);
    });

    it('limits classes with allowed_classes', function () {
        $html = '<p class="keep drop">x</p><p class="drop">y</p>';

        expect(sanitizer()->clean($html))->toBe($html)
            ->and(sanitizer(['allowed_classes' => ['keep']])->clean($html))->toBe('<p class="keep">x</p><p>y</p>');
    });

    it('accepts other frames through iframe_regexp', function () {
        $html = '<iframe src="https://player.vimeo.com/video/1"></iframe><iframe src="https://www.youtube.com/embed/x"></iframe>';

        expect(sanitizer(['iframe_regexp' => '%^https://player\.vimeo\.com/video/%'])->clean($html))
            ->toBe('<iframe src="https://player.vimeo.com/video/1"></iframe>');
    });

    it('lets the configure hook add a tag', function () {
        $hook = function (HTMLPurifier_Config $config) {
            $config->set('HTML.AllowedElements', [...$config->get('HTML.AllowedElements'), 'figure' => true]);
            if ($def = $config->maybeGetRawHTMLDefinition()) {
                $def->addElement('figure', 'Block', 'Flow', 'Common');
            }
        };
        $html = '<figure class="x"><img src="/a.png" alt=""></figure>';

        expect(sanitizer()->clean($html))->toBe('<img src="/a.png" alt="">')
            ->and(sanitizer(['configure' => $hook])->clean($html))->toBe($html);
    });

    it('keeps the limits when the configure hook reads the config or replaces Brume\'s parts', function (Closure $hook) {
        $sanitizer = sanitizer(['configure' => $hook, 'max_depth' => 5, 'max_elements' => 50]);

        expect($sanitizer->clean('<p class="a">x</p>'))->toBe('<p class="a">x</p>')
            ->and($sanitizer->limitViolation(str_repeat('<div>', 6).'x'))->toBe('depth')
            ->and($sanitizer->limitViolation(str_repeat('<i>x</i>', 51)))->toBe('elements')
            ->and($sanitizer->limitViolation('<b>'.str_repeat('<div>x</div>', 20)))->toBe('elements')
            ->and($sanitizer->limitViolation('<p title="'.str_repeat('a', 9000).'">x</p>'))->toBe('attributes');
    })->with([
        'get()' => [fn (HTMLPurifier_Config $config) => $config->get('HTML.Doctype')],
        'getBatch()' => [fn (HTMLPurifier_Config $config) => $config->getBatch('Core')],
        'getCSSDefinition()' => [fn (HTMLPurifier_Config $config) => $config->getCSSDefinition()],
        'its own lexer' => [fn (HTMLPurifier_Config $config) => $config->set('Core.LexerImpl', 'DirectLex')],
        'its own injectors' => [fn (HTMLPurifier_Config $config) => $config->set('AutoFormat.Custom', [])],
    ]);

    it('refuses to run without limits when the configure hook finalises the config or builds the definition', function (Closure $hook, string $loud, bool $quiet) {
        // A host error handler that swallows everything: HTMLPurifier reports a set() on a finalised
        // config with trigger_error only, so nothing else would tell that the limits are off.
        if ($quiet) {
            set_error_handler(fn () => true);
        }
        // Building the definition in the hook already fails loudly under Laravel's error handler
        // (HTMLPurifier does not know `article` without Brume's additions); silenced, Brume refuses.
        $expected = $quiet ? LogicException::class : $loud;
        try {
            expect(fn () => sanitizer(['configure' => $hook])->clean('<p>x</p>'))->toThrow($expected)
                ->and(fn () => sanitizer(['configure' => $hook])->limitViolation(str_repeat('<div>', 300).'x'))->toThrow($expected);
            if ($expected === LogicException::class) {
                expect(fn () => sanitizer(['configure' => $hook])->clean('<p>x</p>'))->toThrow(LogicException::class, 'brume.html.configure');
            }
        } finally {
            if ($quiet) {
                restore_error_handler();
            }
        }
    })->with([
        'finalize()' => [fn (HTMLPurifier_Config $config) => $config->finalize(), LogicException::class],
        'autoFinalize and get()' => [function (HTMLPurifier_Config $config) {
            $config->autoFinalize = true;
            $config->get('HTML.Doctype');
        }, LogicException::class],
        'getHTMLDefinition()' => [fn (HTMLPurifier_Config $config) => $config->getHTMLDefinition(), ErrorException::class],
        'getDefinition(HTML)' => [fn (HTMLPurifier_Config $config) => $config->getDefinition('HTML'), ErrorException::class],
    ])->with(['throwing error handler' => false, 'silent error handler' => true]);

    it('lets the configure hook shrink the CSS whitelist, custom properties included', function () {
        $hook = fn (HTMLPurifier_Config $config) => $config->set('CSS.ForbiddenProperties', ['display' => true, 'color' => true]);

        expect(sanitizer(['configure' => $hook])->clean('<div style="display:flex;color:red;margin:0;">x</div>'))
            ->toBe('<div style="margin:0;">x</div>');
    });

    it('derives the definition id from the effective options', function () {
        $ids = [
            sanitizer()->definitionId(),
            sanitizer(['allow_fixed' => true])->definitionId(),
            sanitizer(['allowed_classes' => ['a']])->definitionId(),
            sanitizer(['iframe_regexp' => '%^https://example\.com/%'])->definitionId(),
            sanitizer(['configure' => fn () => null])->definitionId(),
            sanitizer(['configure' => fn ($c) => $c])->definitionId(),
            sanitizer(['configure' => fn ($c) => $c, 'configure_version' => 2])->definitionId(),
            sanitizer(['reserved_names' => ['cart']])->definitionId(),
        ];

        expect(array_unique($ids))->toHaveCount(count($ids))
            ->and(sanitizer()->definitionId())->toBe($ids[0]);
    });
});

describe('definition cache', function () {
    afterEach(function () {
        foreach ($this->cleanup ?? [] as $dir) {
            @chmod($dir, 0777);
            File::deleteDirectory($dir);
        }
    });

    it('defaults to storage/framework/cache/brume-htmlpurifier and creates it on demand', function () {
        $storage = $this->cleanup[] = tempDir('brume-storage');
        app()->useStoragePath($storage);

        expect(app(HtmlSanitizer::class)->clean('<p>x</p>'))->toBe('<p>x</p>')
            ->and(glob($storage.'/framework/cache/brume-htmlpurifier/HTML/*.ser'))->not->toBeEmpty();
    });

    it('writes nothing with cache_path false', function () {
        $storage = $this->cleanup[] = tempDir('brume-storage');
        app()->useStoragePath($storage);

        expect(sanitizer(['cache_path' => false])->clean('<p>x</p>'))->toBe('<p>x</p>')
            ->and(is_dir($storage.'/framework/cache/brume-htmlpurifier'))->toBeFalse();
    });

    it('falls back to no cache when the path cannot be created', function () {
        $dir = $this->cleanup[] = tempDir('brume-cache');
        file_put_contents($dir.'/file', 'x');

        throwingErrorHandler();
        try {
            $clean = sanitizer(['cache_path' => $dir.'/file/sub'])->clean('<p>x</p>');
        } finally {
            restore_error_handler();
        }

        expect($clean)->toBe('<p>x</p>');
    });

    it('falls back to no cache when the path is not writable', function () {
        $dir = $this->cleanup[] = tempDir('brume-cache');
        chmod($dir, 0555);
        if (is_writable($dir)) {
            $this->markTestSkipped('Running as a user that ignores permissions.');
        }

        throwingErrorHandler();
        try {
            $clean = sanitizer(['cache_path' => $dir])->clean('<p>x</p>');
        } finally {
            restore_error_handler();
        }

        expect($clean)->toBe('<p>x</p>');
    });

    it('never serves a definition cached for other options', function () {
        $dir = $this->cleanup[] = tempDir('brume-cache');
        $hook = function (HTMLPurifier_Config $config) {
            $config->set('HTML.AllowedElements', [...$config->get('HTML.AllowedElements'), 'figure' => true]);
            if ($def = $config->maybeGetRawHTMLDefinition()) {
                $def->addElement('figure', 'Block', 'Flow', 'Common');
            }
        };
        $html = '<figure><p>x</p></figure>';

        expect((new HtmlSanitizer(['cache_path' => $dir]))->clean($html))->toBe('<p>x</p>')
            ->and((new HtmlSanitizer(['cache_path' => $dir, 'configure' => $hook]))->clean($html))->toBe($html)
            ->and((new HtmlSanitizer(['cache_path' => $dir]))->clean($html))->toBe('<p>x</p>')
            ->and((new HtmlSanitizer(['cache_path' => $dir]))->clean('<img src="/a.png">'))->toBe('<img src="/a.png">')
            ->and(glob($dir.'/HTML/*.ser'))->toHaveCount(2);
    });
});

it('builds and cleans without any notice, warning or deprecation under E_ALL', function () {
    throwingErrorHandler();
    try {
        $sanitizer = new HtmlSanitizer(['cache_path' => false, 'allow_fixed' => true, 'allowed_classes' => ['a']]);
        foreach ([...legacyHtmlFixtures(), 'hostile.html'] as $name) {
            $sanitizer->clean(htmlFixture($name));
        }
        $cached = tempDir('brume-cache');
        (new HtmlSanitizer(['cache_path' => $cached]))->clean(htmlFixture('01-nested-layout.html'));
        (new HtmlSanitizer(['cache_path' => $cached]))->clean(htmlFixture('01-nested-layout.html'));
    } finally {
        restore_error_handler();
        File::deleteDirectory($cached ?? '');
    }

    expect(true)->toBeTrue();
});

it('is bound as a singleton configured from brume.html', function () {
    config(['brume.html.allowed_classes' => ['only']]);
    app()->forgetInstance(HtmlSanitizer::class);

    expect(app(HtmlSanitizer::class))->toBe(app(HtmlSanitizer::class))
        ->and(app(HtmlSanitizer::class)->clean('<p class="only other">x</p>'))->toBe('<p class="only">x</p>')
        ->and(config('brume.html.allow_fixed'))->toBeFalse()
        ->and(config('brume.html.max_bytes'))->toBe(300_000)
        ->and(config('brume.html.max_depth'))->toBe(250)
        ->and(config('brume.html.max_attribute_bytes'))->toBe(8192)
        ->and(config('brume.html.max_classes'))->toBe(200)
        ->and(config('brume.html.max_elements'))->toBe(20_000)
        ->and(config('brume.html.reserved_names'))->toBe([])
        ->and(config('brume.html.configure_version'))->toBeNull()
        ->and(config('brume.html.iframe_regexp'))->toBe(HtmlSanitizer::DEFAULT_IFRAME_REGEXP);
});

describe('empty documents', function () {
    it('cleans to null', function (?string $html) {
        expect(sanitizer()->clean($html))->toBeNull();
    })->with([null, '', '<p><br></p>', '<p>&nbsp;</p>', "  \n", '<script>alert(1)</script>', '<p><span></span></p>']);

    // Ported 1:1 from brume-react's empty.test.ts (undefined maps to null). Keep both tables identical.
    it('isEmpty matches isEmptyHtml', function (?string $html, bool $expected) {
        expect(HtmlSanitizer::isEmpty($html))->toBe($expected);
    })->with([
        ['<p>< 5</p>', false],
        ['<p>&#160;</p>', true],
        ['<p>&#xA0;</p>', true],
        ['<p>&#xa0;</p>', true],
        ['<p>&#XA0;</p>', true],
        ['<p>&NBSP;</p>', false],
        ["<p>\u{FEFF}</p>", false],
        ["<p>\u{200B}</p>", false],
        ["<p>\u{2028}</p>", false],
        ['<p>&ensp;</p>', false],
        ['<p>0</p>', false],
        ['<P><IMG SRC=x></P>', false],
        ['<!-- <img> --><p></p>', true],
        ['<p><span></span></p>', true],
        ['<hrx></hrx>', true],
        ["<p>\f\v\r</p>", true],
        [null, true],
        ['', true],
        ["   \n\t ", true],
        ['<p><br></p>', true],
        ['<p>&nbsp;</p>', true],
        ['<div><p> </p></div>', true],
        ["<p>\u{00A0}</p><!-- note --><p><br/></p>", true],
        ['<p><img src="x"></p>', false],
        ['<hr>', false],
        ['<iframe src="https://example.com"></iframe>', false],
        ['<table><tr><td></td></tr></table>', false],
        ['<p><input type="checkbox"></p>', false],
        ['a', false],
        ['<p>&nbsp;a</p>', false],
    ]);

    it('isEmpty follows the byte-level rule at its edges', function (string $html, bool $expected) {
        expect(HtmlSanitizer::isEmpty($html))->toBe($expected);
    })->with([
        'media tag then form feed' => ["<img\fsrc=x>", false],
        'media tag then vertical tab is not a media tag' => ["<img\vsrc=x>", true],
        'media tag then slash' => ['<hr/>', false],
        'comment spanning lines' => ["<!--\n<img>\n--><p></p>", true],
        'U+00A0 bytes only' => ["\xC2\xA0", true],
        'lone A0 byte is content' => ["\xA0", false],
        'uppercase hex digit' => ['&#XA0;', true],
        'tag with newline inside' => ["<p\nclass=\"x\"></p>", true],
    ]);
});

it('cleans a 20 KB document well under 200 ms after warm-up', function () {
    $html = '';
    while (strlen($html) < 20_000) {
        foreach (legacyHtmlFixtures() as $name) {
            $html .= htmlFixture($name);
        }
    }
    $sanitizer = sanitizer();
    $sanitizer->clean($html);

    $best = INF;
    foreach (range(1, 3) as $_) {
        $start = hrtime(true);
        $sanitizer->clean($html);
        $best = min($best, (hrtime(true) - $start) / 1e6);
    }

    expect($best)->toBeLessThan(200.0);
});

it('re-parses with the HTML5 parser without creating elements or attributes', function (string $name) {
    if (! class_exists(HTMLDocument::class)) {
        $this->markTestSkipped('Dom\HTMLDocument needs PHP 8.4+.');
    }
    $clean = sanitizer()->clean(htmlFixture($name));

    // Every element a browser builds from the output must be one the output spells out (same
    // name, same attributes). The HTML5 parser may clone a link or close a <p> early around
    // block content (input that already nests blocks inside <p> renders the same way), but it
    // must never invent anything.
    preg_match_all('/<([a-z][a-z0-9]*)((?:\s[^>]*)?)>/', $clean, $tags, PREG_SET_ORDER);
    $written = [];
    foreach ($tags as [, $tag, $attributes]) {
        preg_match_all('/([^\s=]+)(?:="([^"]*)")?/', $attributes, $pairs, PREG_SET_ORDER);
        $attrs = [];
        foreach ($pairs as $pair) {
            $attrs[$pair[1]] = html_entity_decode($pair[2] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        ksort($attrs);
        $written[$tag.json_encode($attrs, JSON_UNESCAPED_UNICODE)] = true;
    }
    $doc = HTMLDocument::createFromString('<!doctype html><html><body>'.$clean.'</body></html>', LIBXML_NOERROR);
    foreach ($doc->body->querySelectorAll('*') as $element) {
        $attrs = [];
        foreach ($element->attributes as $attribute) {
            $attrs[$attribute->name] = $attribute->value;
        }
        ksort($attrs);
        expect($written)->toHaveKey(strtolower($element->localName).json_encode($attrs, JSON_UNESCAPED_UNICODE));
    }
})->with([...legacyHtmlFixtures(), 'hostile.html']);

describe('element amplification', function () {
    it('rejects formatting-reopening floods before HTMLPurifier builds them', function (int $k, int $m) {
        [$exit, $result, $ms, $mb] = cleanInChild("amp($k, $m)");

        expect($exit)->toBe(0)
            ->and($result)->toBe('rejected:elements')
            ->and($ms)->toBeLessThan(300.0)
            ->and($mb)->toBeLessThan(20.0);
    })->with([[128, 400], [249, 1000], [249, 3000], [64, 2000], [8, 20_000]]);

    it('stops the flood at runtime even with the projection switched off', function (int $k, int $m) {
        [$exit, $result, , $mb] = cleanInChild("amp($k, $m)", ['projection' => false]);

        expect($exit)->toBe(0)
            ->and($result)->toBe('rejected:elements')
            ->and($mb)->toBeLessThan(80.0);
    })->with([[249, 3000], [64, 2000]]);

    it('cleans the largest amplifying input the limits accept within the memory budget', function () {
        // 8 open (6 formatting) elements: the projection charges each block about 61 elements,
        // so 327 blocks is the most the default limit lets through (and 328 is rejected).
        [$exit, $result, , $mb, $elements] = cleanInChild('amp(8, 327)');
        [, $over] = cleanInChild('amp(8, 328)');

        expect($exit)->toBe(0)
            ->and($result)->toBe('accepted')
            ->and($over)->toBe('rejected:elements')
            ->and($elements)->toBeLessThanOrEqual(20_000)
            ->and($mb)->toBeLessThan(60.0);
    });

    it('keeps the strongest amplifier a search found within the budget', function () {
        // Found by maximising output ÷ input elements over accepted inputs (fix round 3): about 250
        // parsed elements become about 9 800. The projection still bounds it below max_elements.
        [$exit, $result, $ms, $mb, $elements] = cleanInChild('file_get_contents('.var_export(HTML_FIXTURES.'/amplifier-search-worst.txt', true).')');

        expect($exit)->toBe(0)
            ->and($result)->toBe('accepted')
            ->and($elements)->toBeLessThanOrEqual(20_000)
            ->and($ms)->toBeLessThan(1000.0)
            ->and($mb)->toBeLessThan(60.0);
    });

    it('never projects fewer elements than HTMLPurifier produces', function () {
        $parts = ['<b>', '<i>', '<u>', '<s>', '<em>', '<strong>', '<a href="/x">', '</b>', '</i>', '</strong>', '</a>', '<sup>', '<span>', '</span>',
            '<div>', '</div>', '<p>', '</p>', '<h2>', '</h2>', '<ul>', '<ol>', '<li>', '</li>', '</ul>', '<table>', '<tr>', '<td>', '</td>', '<tbody>',
            '<col>', '<blockquote>', '<hr>', '<br>', '<label>', '<div>x</div>', '<li>y</li>', 'text', ' '];
        $sanitizer = sanitizer(['max_elements' => null, 'max_bytes' => null, 'max_depth' => null]);
        $worst = 0.0;
        $seen = [];
        mt_srand(31337);
        try {
            foreach (range(1, 1500) as $i) {
                $html = $i % 10 === 0 ? amp(mt_rand(1, 24), mt_rand(1, 40), ['<div>x</div>', '<p>p</p>', '<li>l</li>', '<h2>h</h2>'][mt_rand(0, 3)]) : '';
                foreach (range(1, mt_rand(1, 60)) as $__) {
                    $html .= $parts[mt_rand(0, count($parts) - 1)];
                }
                // clean() is memoised: a repeated input would report the previous run's token count.
                if (isset($seen[$html])) {
                    continue;
                }
                $seen[$html] = true;
                $stats = $sanitizer->treeStats($html);
                $actual = outputElements($sanitizer->clean($html));

                expect($actual)->toBeLessThanOrEqual($stats['projected'])
                    ->and($sanitizer->lastTokenCount())->toBeLessThanOrEqual($stats['projected']);
                $worst = max($worst, $actual / max(1, $stats['projected']));
            }
        } finally {
            mt_srand();
        }

        expect($worst)->toBeLessThanOrEqual(1.0);
    });

    it('keeps formatting around blocks, reopened inside each block', function (string $html, string $expected) {
        expect(sanitizer()->clean($html))->toBe($expected)
            ->and(sanitizer()->clean($expected))->toBe($expected);
    })->with([
        // Empty shells are what HTMLPurifier's carry-over leaves around each block; they render nothing.
        ['<strong><p>a</p><p>b</p></strong>', '<strong></strong><p><strong>a</strong></p><strong></strong><p><strong>b</strong></p><strong></strong>'],
        ['<b>a<div>x</div>y</b>', '<b>a</b><div><b>x</b></div><b>y</b>'],
        [str_repeat('<b><div>block</div></b>', 20), str_repeat('<b></b><div><b>block</b></div><b></b>', 20)],
    ]);
});

describe('attribute cost', function () {
    it('allows an attribute value of max_attribute_bytes and rejects one byte more', function () {
        $html = fn (int $bytes) => '<p title="'.str_repeat('a', $bytes).'">x</p>';

        expect(sanitizer()->limitViolation($html(8192)))->toBeNull()
            ->and(sanitizer()->limitViolation($html(8193)))->toBe('attributes')
            ->and(fn () => sanitizer()->clean($html(8193)))->toThrow(function (HtmlRejected $e) {
                expect($e->reason)->toBe('attributes')
                    ->and($e->limit)->toBe(8192)
                    ->and($e->getMessage())->toContain('brume.html.max_attribute_bytes')->toContain('brume.html.max_classes');
            })
            ->and(sanitizer(['max_attribute_bytes' => 100])->limitViolation($html(101)))->toBe('attributes')
            // Measured as parsed: entities count as the character they stand for.
            ->and(sanitizer(['max_attribute_bytes' => 100])->limitViolation('<p title="'.str_repeat('&amp;', 100).'">x</p>'))->toBeNull()
            ->and(sanitizer(['max_attribute_bytes' => null])->limitViolation($html(20_000)))->toBeNull()
            // An attribute the whitelist would drop is measured too: it is parsed and carried along first.
            ->and(sanitizer()->limitViolation('<p onclick="'.str_repeat('a', 8193).'">x</p>'))->toBe('attributes');
    });

    it('allows max_classes classes on an element and rejects one more', function () {
        $html = fn (int $classes) => '<p class="'.implode(' ', array_map(fn ($i) => 'c'.$i, range(1, $classes))).'">x</p>';

        expect(sanitizer()->limitViolation($html(200)))->toBeNull()
            ->and(sanitizer()->limitViolation($html(201)))->toBe('attributes')
            ->and(sanitizer()->limitViolation('<p class="'.str_repeat("a \n", 201).'">x</p>'))->toBe('attributes')
            ->and(sanitizer(['max_classes' => 3])->limitViolation('<p class=" a  b c ">x</p><p class="d e f">y</p>'))->toBeNull()
            ->and(sanitizer(['max_classes' => 3])->limitViolation('<p class="a b c d">x</p>'))->toBe('attributes')
            ->and(sanitizer(['max_classes' => null])->limitViolation($html(1000)))->toBeNull();
    });

    it('refuses a tag crowded with attributes before the parser compares them all', function () {
        $html = fn (int $attributes) => '<p '.implode(' ', array_map(fn ($i) => 'a'.$i, range(1, $attributes))).'>x</p>';

        // max_attribute_bytes ÷ 8 words in one tag: 1,024 by default.
        expect(sanitizer()->clean($html(1000)))->toBe('<p>x</p>')
            ->and(sanitizer()->limitViolation($html(1100)))->toBe('attributes')
            // Counted before parsing, where a quoted value cannot be told from more attributes:
            // the words inside values count too.
            ->and(sanitizer()->limitViolation('<p title="'.str_repeat('word ', 1000).'">x</p>'))->toBeNull()
            ->and(sanitizer()->limitViolation('<p title="'.str_repeat('word ', 1100).'">x</p>'))->toBe('attributes');
    });

    it('leaves the fixtures far below the attribute limits', function () {
        $longest = $classes = 0;
        foreach (legacyHtmlFixtures() as $name) {
            foreach (HTMLDocument::createFromString('<!DOCTYPE html><body>'.htmlFixture($name), LIBXML_NOERROR)->getElementsByTagName('*') as $element) {
                foreach ($element->attributes as $attribute) {
                    $longest = max($longest, strlen($attribute->value));
                }
                $classes = max($classes, count(preg_split('/\s+/', (string) $element->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY)));
            }
        }

        // Largest in the fixtures: a 212-byte style, 3 classes.
        expect($longest)->toBeGreaterThan(100)->toBeLessThan(HtmlSanitizer::DEFAULT_MAX_ATTRIBUTE_BYTES / 20)
            ->and($classes)->toBeGreaterThan(1)->toBeLessThan(HtmlSanitizer::DEFAULT_MAX_CLASSES / 20);
    });

    it('drops duplicate classes like HTMLPurifier does, keeping the first of each', function () {
        expect(sanitizer()->clean('<p class="b a b 1 01 -0 0 a  b">x</p>'))->toBe('<p class="b a 1 01 -0 0">x</p>')
            ->and(sanitizer(['allowed_classes' => ['a', '1']])->clean('<p class="b a b 1 01 a">x</p>'))->toBe('<p class="a 1">x</p>');
    });

    it('rejects the round-3 attribute floods at once', function (string $expression, string $reason) {
        [$exit, $result, $ms, $mb] = cleanInChild($expression);

        expect($exit)->toBe(0)
            ->and($result)->toBe('rejected:'.$reason)
            ->and($ms)->toBeLessThan(300.0)
            ->and($mb)->toBeLessThan(20.0);
    })->with([
        // Each of these exhausted memory or took seconds: every reopened copy of the formatting
        // element carried the attribute, and was validated and serialised on its own.
        'one 200 KB class, reopened in 1000 blocks' => ["attributeFlood('class', str_repeat('a', 200000), 1000)", 'attributes'],
        'a 100 KB url() in a style, reopened in 1000 blocks' => ["'<strong>'.attributeFlood('style', 'background-image:url(https://e.x/'.str_repeat('a', 100000).')', 1000, 'em')", 'attributes'],
        '10 KB of classes, reopened in 1000 blocks' => ['attributeFlood("class", substr(implode(" ", range(1000, 4000)), 0, 10000), 1000)', 'attributes'],
        '50 KB of classes, reopened in 100 blocks' => ['attributeFlood("class", substr(implode(" ", range(10000, 22000)), 0, 50000), 100)', 'attributes'],
        '45,000 distinct classes on one element' => ['"<p class=\"".implode(" ", range(0, 45000))."\">x</p>"', 'attributes'],
        // Within the attribute limits, the copies still add up: the byte budget refuses them.
        '200 classes, reopened in 1000 blocks' => ['attributeFlood("class", implode(" ", range(1000, 1199)), 1000)', 'bytes'],
        'an 8 KB style, reopened in 100 blocks' => ["attributeFlood('style', substr(str_repeat('background:url(a);', 500), 0, 8000), 100)", 'bytes'],
        'three formatting elements with 2 KB titles, reopened in 300 blocks' => ["str_repeat('<a title=\"'.str_repeat('t', 2000).'\">', 3).str_repeat('<li>x</li>', 300)", 'bytes'],
    ]);

    it('bounds the bytes with the attribute limits off, before purifying and again while purifying', function (string $expression, bool $projection) {
        [$exit, $result, $ms, $mb] = cleanInChild($expression, ['max_attribute_bytes' => null, 'max_classes' => null, 'projection' => $projection]);

        expect($exit)->toBe(0)
            ->and($result)->toBe('rejected:bytes')
            ->and($ms)->toBeLessThan(300.0)
            ->and($mb)->toBeLessThan(20.0);
    })->with([
        'one 200 KB class' => ["attributeFlood('class', str_repeat('a', 200000), 1000)"],
        'a 100 KB url()' => ["'<strong>'.attributeFlood('style', 'background-image:url(https://e.x/'.str_repeat('a', 100000).')', 1000, 'em')"],
        '10 KB of classes' => ['attributeFlood("class", substr(implode(" ", range(1000, 4000)), 0, 10000), 1000)'],
        'nothing but ampersands' => ["str_repeat('&', 290000)"],
    ])->with(['projection' => true, 'runtime budget alone' => false]);

    it('cleans 45,000 distinct classes in linear time when the limits allow them', function () {
        [$exit, $result, $ms, $mb] = cleanInChild('"<p class=\"".implode(" ", range(0, 45000))."\">x</p>"', ['max_attribute_bytes' => null, 'max_classes' => null]);

        // HTMLPurifier's own class definition needed 6 s for 60,000 of them.
        expect($exit)->toBe(0)
            ->and($result)->toBe('accepted')
            ->and($ms)->toBeLessThan(300.0)
            ->and($mb)->toBeLessThan(20.0);
    });

    it('cleans the largest attribute-carrying amplifier the budget accepts within the targets', function () {
        // 140 blocks × 2 copies of a 1,000-byte class: about 287 KB of output from 2.7 KB of input.
        [$exit, $result, $ms, $mb] = cleanInChild("attributeFlood('class', str_repeat('a', 1000), 140)");

        expect($exit)->toBe(0)
            ->and($result)->toBe('accepted')
            ->and($ms)->toBeLessThan(1500.0)
            ->and($mb)->toBeLessThan(60.0);
    });

    it('cleans the worst accepted inputs the resource search found within the targets', function (string $expression) {
        // tests/Feature/Html/resource-search.php, 10,000 evaluations per objective (fix round 4).
        [$exit, $result, $ms, $mb] = cleanInChild($expression);

        expect($exit)->toBe(0)
            ->and($result)->toBe('accepted')
            ->and($ms)->toBeLessThan(1500.0)
            ->and($mb)->toBeLessThan(60.0);
    })->with([
        // Slowest and hungriest of all (about 0.7 s, 53 MB): a full document that ends in a cut-off
        // link tag inside an open link, so the nested-link backstop has to clean it a second time.
        'links, then a link cut off inside a link' => ["str_repeat('<a href=\"/x\">y</a>', 16600).'<a title=\"t\">m<a href=\"m'"],
        'as many links as fit' => ["str_repeat('<a href=\"/x\">y</a>', 16000)"],
        'as many elements as fit' => ["str_repeat('<p>x</p>', 20000)"],
        'unclosed blocks' => ["str_repeat('<div>x', 240).str_repeat('<p>x', 19000)"],
        'table cells' => ["'<table>'.str_repeat('<tr><td>a</td><th>b</th></tr>', 6600).'</table>'"],
        '36 styles of 8 KB' => ["str_repeat('<p style=\"'.substr(str_repeat('background:url(a);', 500), 0, 8190).'\">x</p>', 36)"],
        '1,000 attributes in each of 61 tags' => ["str_repeat('<p '.implode(' ', array_map(fn (\$i) => 'a'.\$i, range(1, 1000))).'>x', 61)"],
    ]);

    it('rejects a value that only passes the attribute limit before it is rewritten', function () {
        // 6 KB as written, 18 KB once percent-encoded: the result could not be cleaned again.
        expect(sanitizer()->limitViolation('<a href="/'.str_repeat("\u{e9}", 3000).'">x</a>'))->toBe('attributes')
            ->and(sanitizer()->limitViolation('<a href="/'.str_repeat("\u{e9}", 1300).'">x</a>'))->toBeNull();
    });

    it('never charges an attribute fewer bytes than HTMLPurifier writes for it', function (string $attribute, string $value) {
        $element = match ($attribute) {
            'src', 'alt', 'width' => 'img',
            'data-th' => 'td',
            default => 'a',
        };
        $attributes = $element === 'img' && $attribute !== 'src' ? ['src' => '/a.png', $attribute => $value] : [$attribute => $value];
        $html = '<'.$element;
        foreach ($attributes as $name => $text) {
            $html .= ' '.$name.'="'.htmlspecialchars($text).'"';
        }
        $html = $element === 'td' ? '<table><tr>'.$html.'>x</td></tr></table>' : $html.'>'.($element === 'a' ? 'x</a>' : '');
        $sanitizer = sanitizer(['max_attribute_bytes' => null, 'max_classes' => null]);
        $clean = (string) $sanitizer->clean($html);
        $overhead = $element === 'td' ? strlen('<table><tr></tr></table>x') : ($element === 'a' ? 1 : 0);

        expect(strlen($clean) - $overhead)->toBeLessThanOrEqual(MeasuringLexer::elementBytes($element, $attributes))
            ->and(strlen($clean))->toBeLessThanOrEqual($sanitizer->treeStats($html)['bytes'])
            ->and(strlen($clean))->toBeLessThanOrEqual($sanitizer->lastByteCount());
    })->with(function () {
        $values = [
            'style' => ['color:red', 'color:tan;color:red', 'background:url(a)', 'background:url(a b)', "background-image:url(\u{e9})", 'list-style:url(a)', 'font-family:a,b,c,d', 'font-family:1,2,3', "font-family:\u{e9},a b,'c'",
                'font-family:"a",\\62 ', 'margin:0', 'border:0 solid red', 'top:0', 'color:#fff', 'color:rgb(1,2,3)', 'background:red url(a) no-repeat top', 'border-color:red red red red', 'width:1', 'font:1px a',
                'text-decoration:underline', 'a:b;c:d;;;color:red', 'COLOR:RED', 'color:red !important', 'background-position:left', 'box-shadow:0 0 0 0 red,0 0 0 0 red', 'gap:1px', 'opacity:1', 'line-height:1', 'border-spacing:1px 1px'],
            'href' => ['/a b', "/\u{e9}\u{e9}", '/<>"', '/a?b=c&d=e', 'tel:1-2-3', 'mailto:a@b.c', '//e.x', 'a', '%', '%zz', '/%41', "/\t", '?', '#', 'http://e.x', 'HTTP://E.X/A', 'http://[::1]/', "http://\u{4e2d}.x/", '/{}|\\^`', "/'()"],
            'src' => ['/a b.png', "/\u{4e2d}.png", '/a.png?x=1&y=2', 'a', '/"'],
            'title' => ['"', '&', '<>', "\u{e9}", "a\tb", "'"],
            'alt' => ['"quoted"', ''],
            'class' => ['a', 'a a b', '1', '"'],
            'id' => ['a', 'a"b', "\u{e9}"],
            'rel' => ['nofollow', 'nofollow noopener x'],
            'target' => ['_blank', 'blank'],
            'width' => ['1', '100%', '1px'],
            'data-th' => ['"', '&<>'],
        ];
        foreach ($values as $attribute => $list) {
            foreach ($list as $value) {
                yield "$attribute=$value" => [$attribute, $value];
                yield "$attribute=$value ×40" => [$attribute, substr(str_repeat($value.($attribute === 'style' ? ';' : ''), 40), 0, 400)];
            }
        }
    });

    it('never projects or charges fewer bytes than HTMLPurifier writes', function () {
        $attributes = ['', '', '', ' class="a b c"', ' style="color:red;background:url(a) no-repeat"', ' title="&quot;&amp;"', ' id="x"', ' style="font-family:a,b;margin:0"', ' onclick="x()" data-x="y"', " class='c d'"];
        $open = ['b', 'i', 'u', 's', 'em', 'strong', 'a href="/x y"', 'sup', 'span', 'div', 'p', 'h2', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'tbody', 'caption', 'blockquote', 'label'];
        $other = ['</b>', '</i>', '</strong>', '</a>', '</span>', '</div>', '</p>', '</li>', '</ul>', '</td>', '</table>', '<col>', '<hr>', '<br>', '<img src="/a b.png" alt="&quot;">',
            '<div>x</div>', '<li>y</li>', 'text', ' ', '&amp;', '&lt;', '>', '"', "\u{4e2d}", '&nbsp;', '<!-- c -->'];
        $sanitizer = sanitizer(['max_bytes' => null, 'max_depth' => null, 'max_elements' => null, 'max_attribute_bytes' => null, 'max_classes' => null]);
        mt_srand(20261125);
        try {
            foreach (range(1, 1500) as $_) {
                $html = '';
                foreach (range(1, mt_rand(1, 40)) as $__) {
                    $html .= mt_rand(0, 9) < 5
                        ? '<'.$open[mt_rand(0, count($open) - 1)].$attributes[mt_rand(0, count($attributes) - 1)].'>'
                        : $other[mt_rand(0, count($other) - 1)];
                }
                $clean = (string) $sanitizer->clean($html);
                $charged = $sanitizer->lastByteCount();

                expect(strlen($clean))->toBeLessThanOrEqual($sanitizer->treeStats($html)['bytes'], $html)
                    ->and(strlen($clean))->toBeLessThanOrEqual($charged, $html);
            }
        } finally {
            mt_srand();
        }
    });

    it('charges every reopened copy its attributes', function () {
        $sanitizer = sanitizer();
        $html = attributeFlood('class', str_repeat('a', 500), 50);
        $clean = $sanitizer->clean($html);
        $stats = $sanitizer->treeStats($html);

        // 1 + 2 × 50 copies of a 500-byte class: 51 are written with it (the rest are empty shells too).
        expect(substr_count($clean, str_repeat('a', 500)))->toBe(101)
            ->and($stats['bytes'])->toBeGreaterThanOrEqual(strlen($clean))
            ->and($stats['bytes'])->toBeLessThan(2 * strlen($clean))
            ->and($sanitizer->lastByteCount())->toBeGreaterThanOrEqual(strlen($clean));
    });
});

describe('table parts', function () {
    it('removes a col outside a table on the first pass', function (string $html, ?string $expected) {
        $once = sanitizer()->clean($html);

        expect($once)->toBe($expected)
            ->and(sanitizer()->clean((string) $once))->toBe($once);
    })->with([
        ['<ul><col>x</ul>', '<ul><li>x</li></ul>'],
        // libxml's HTML 4 parser closes the <p> at the <col> itself.
        ['<p>a<col>b</p>', '<p>a</p>b'],
        ['<div><col><col span="2"></div><p>x</p>', '<div></div><p>x</p>'],
        ['<table><col span="2"><tr><td>c</td></tr></table>', '<table><col span="2"><tr><td>c</td></tr></table>'],
        ['<table><colgroup><col></colgroup><tr><td>c</td></tr></table>', '<table><colgroup><col></colgroup><tr><td>c</td></tr></table>'],
    ]);

    it('keeps caption and tfoot with their content', function (string $html, string $expected) {
        expect(sanitizer()->clean($html))->toBe($expected)
            ->and(sanitizer()->clean($expected))->toBe($expected);
    })->with([
        ['<table><caption class="c" style="text-align:left;">規格</caption><tbody><tr><td>x</td></tr></tbody></table>',
            '<table><caption class="c" style="text-align:left;">規格</caption><tbody><tr><td>x</td></tr></tbody></table>'],
        ['<table><thead><tr><th>h</th></tr></thead><tfoot class="f"><tr><td>總計</td></tr></tfoot><tbody><tr><td>x</td></tr></tbody></table>',
            '<table><thead><tr><th>h</th></tr></thead><tfoot class="f"><tr><td>總計</td></tr></tfoot><tbody><tr><td>x</td></tr></tbody></table>'],
        // HTML 4 order: a tfoot written after the tbody (as TinyMCE does) moves before it; browsers
        // draw the footer at the bottom either way.
        ['<table><tbody><tr><td>x</td></tr></tbody><tfoot><tr><td>f</td></tr></tfoot></table>',
            '<table><tfoot><tr><td>f</td></tr></tfoot><tbody><tr><td>x</td></tr></tbody></table>'],
    ]);

    it('keeps a caption with block content inside its table', function (string $html, string $expected) {
        expect(sanitizer()->clean($html))->toBe($expected)
            ->and(sanitizer()->clean($expected))->toBe($expected);
    })->with([
        // Fix round 4: with HTML 4's inline-only caption this came back as `<p>y</p>a`.
        ['<table><caption><b><p>y</p></b></caption><tr><td>a</td></tr></table>',
            '<table><caption><b></b><p>y</p></caption><tr><td>a</td></tr></table>'],
        ['<table><caption><p>y</p></caption><tr><td>a</td></tr></table>', '<table><caption><p>y</p></caption><tr><td>a</td></tr></table>'],
        ['<table><caption><a href="/x"><div>link</div></a> t <h2>h</h2><ul><li>i</li></ul></caption><tbody><tr><td>a</td></tr></tbody></table>',
            '<table><caption><a href="/x"><div>link</div></a> t <h2>h</h2><ul><li>i</li></ul></caption><tbody><tr><td>a</td></tr></tbody></table>'],
        ['<table><caption><strong><em><div>x</div></em></strong> z</caption><tr><td>a</td></tr></table>',
            '<table><caption><strong><em></em></strong><div><strong><em>x</em></strong></div><strong><em></em></strong> z</caption><tr><td>a</td></tr></table>'],
        ['<table><caption><table><tr><td>in</td></tr></table></caption><tr><td>a</td></tr></table>',
            '<table><caption><table><tr><td>in</td></tr></table></caption><tr><td>a</td></tr></table>'],
        // Rows written inside a caption: the parser closes the caption first.
        ['<table><caption>c<tr><td>q</td></tr></caption><tr><td>a</td></tr></table>', '<table><caption>c</caption><tr><td>q</td></tr><tr><td>a</td></tr></table>'],
    ]);

    it('never loses the table, the text or the links to well-formed content in its caption', function () {
        // Lists and tables are only generated outside b/i/u/s/em/strong: HTMLPurifier unwraps those
        // inside formatting elements anywhere, caption or not.
        $flow = function (int $depth, bool $formatting = false) use (&$flow): string {
            $inline = ['b', 'i', 'strong', 'em', 'u', 's', 'span', 'sup', 'sub', 'label'];
            $block = ['p', 'div', 'h2', 'h4', 'blockquote', 'article'];
            $out = '';
            for ($k = mt_rand(1, 3); $k > 0; $k--) {
                $pick = mt_rand(0, 11);
                if ($depth <= 0 || $pick < 3) {
                    $out .= ['T'.mt_rand(10, 99), ' ', '<br>', '<img src="/a.png" alt="">', '<hr>'][mt_rand(0, 4)];
                } elseif ($pick < 6) {
                    $tag = $inline[mt_rand(0, 9)];
                    $out .= "<$tag>".$flow($depth - 1, $formatting || array_search($tag, $inline, true) < 6)."</$tag>";
                } elseif ($pick < 9) {
                    $tag = $block[mt_rand(0, 5)];
                    $out .= "<$tag>".$flow($depth - 1, $formatting)."</$tag>";
                } elseif ($pick === 9) {
                    $out .= '<a href="/L'.mt_rand(10, 99).'">'.$flow($depth - 1, $formatting).'</a>';
                } elseif ($formatting) {
                    $out .= 'T'.mt_rand(10, 99);
                } elseif ($pick === 10) {
                    $out .= '<ul><li>'.$flow($depth - 1).'</li><li>T'.mt_rand(10, 99).'</li></ul>';
                } else {
                    $out .= '<table><tr><td>'.$flow($depth - 1).'</td></tr></table>';
                }
            }

            return $out;
        };
        $wraps = ['%s', '<div>%s</div>', '<ul><li>%s</li></ul>', '<table><tr><td>%s</td></tr></table>', '<a href="/o">%s</a>', '<span>%s</span>', '<blockquote>%s</blockquote>'];
        $words = fn (string $html) => preg_match_all('~T\d\d|ROWCELL~', $html, $found) ? $found[0] : [];
        $links = fn (string $html) => preg_match_all('~href="(/L\d\d)"~', $html, $found) ? $found[1] : [];
        $sanitizer = sanitizer();
        mt_srand(20261124);
        try {
            foreach (range(1, 400) as $_) {
                $html = sprintf($wraps[mt_rand(0, 6)], '<table><caption>'.$flow(mt_rand(1, 4)).'</caption><tr><td>ROWCELL</td></tr></table>');
                $clean = (string) $sanitizer->clean($html);

                expect($clean)->toMatch('~<table><caption>.*</caption><tr><td>ROWCELL</td></tr></table>~s', $html)
                    ->and($words($clean))->toBe($words($html), $html)
                    // Links nested in links are unwrapped; none may appear from nowhere, and the first stays.
                    ->and(array_diff($links($clean), $links($html)))->toBe([], $html)
                    ->and(array_slice($links($clean), 0, 1))->toBe(str_contains($html, '<a href="/o">') ? [] : array_slice($links($html), 0, 1), $html)
                    ->and($sanitizer->clean($clean))->toBe($clean, $html);
            }
        } finally {
            mt_srand();
        }
    });
});

describe('links and spans around blocks', function () {
    it('lets a link or span wrap any flow content, in any context', function (string $html) {
        expect(sanitizer()->clean($html))->toBe($html);
    })->with([
        '<a href="/a"><h2>t</h2></a>',
        '<a href="/a"><ul><li>x</li></ul></a>',
        '<a href="/a"><ol><li>x</li></ol></a>',
        '<a href="/a"><div><img src="/i.png" alt=""></div></a>',
        '<a href="/a"><table><tbody><tr><td>c</td></tr></tbody></table></a>',
        '<a href="/a"><p>x</p><blockquote>q</blockquote><hr></a>',
        '<a href="/a">text <b>bold</b> <img src="/i.png" alt=""></a>',
        '<p><a href="/a"><h2>x</h2></a></p>',
        '<div><a href="/a"><div>x</div></a></div>',
        '<ul><li><a href="/a"><ol><li>x</li></ol></a></li></ul>',
        '<table><tbody><tr><td><a href="/a"><h3>x</h3></a></td></tr></tbody></table>',
        '<span><a href="/a"><div>x</div></a></span>',
        '<span><div>x</div></span>',
        '<span class="x"><h2>t</h2><ul><li>x</li></ul></span>',
        '<p><span><div>x</div></span></p>',
    ]);

    it('keeps > inside quoted attribute values and leaves non-tags alone', function (string $html, string $expected) {
        expect(sanitizer()->clean($html))->toBe($expected);
    })->with([
        ['<p><a href="/a" title=\'x>y\'><img src="/a.png" alt="A > B"></a></p>', '<p><a href="/a" title="x&gt;y"><img src="/a.png" alt="A &gt; B"></a></p>'],
        ['<script>var s = "<a href=x>";</script><p>ok</p>', '<p>ok</p>'],
        ['<!-- <a href="/x"> --><p>ok</p>', '<p>ok</p>'],
        ['<p>1 < 2, <abbr>a</abbr> <article-x>b</article-x></p>', '<p>1 &lt; 2, a b</p>'],
        // Not a link: the alias is private to each clean, and an unknown element like any other.
        ['<brumelink href="/x">alias spelt out</brumelink>', 'alias spelt out'],
    ]);

    it('unwraps a link nested in a link, keeping its content', function (string $html, string $expected) {
        expect(sanitizer()->clean($html))->toBe($expected);
    })->with([
        ['<a href="/a">pre<a href="/b">x</a>post</a>', '<a href="/a">prexpost</a>'],
        ['<a href="/a"><div><a href="/b"><img src="/i.png" alt="">x</a></div></a>', '<a href="/a"><div><img src="/i.png" alt="">x</div></a>'],
        ['<a href="/a"><a href="/b"><a href="/c">deep</a></a> tail</a>', '<a href="/a">deep tail</a>'],
        ['<p><a href="/a"><span><a href="/b">x</a></span></a> <a href="/c">y</a></p>', '<p><a href="/a"><span>x</span></a> <a href="/c">y</a></p>'],
        ['<A HREF="/a">x<A href="/b" title="t">y</A></A>', '<a href="/a">xy</a>'],
    ]);
});

describe('iframe embeds', function () {
    it('accepts only real YouTube embeds by default', function (string $src, bool $kept) {
        $clean = sanitizer()->clean('<p>v<iframe src="'.$src.'"></iframe></p>');

        expect($clean)->toBe($kept ? '<p>v<iframe src="'.$src.'"></iframe></p>' : '<p>v</p>');
    })->with([
        'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', true],
        'embed with start' => ['https://www.youtube.com/embed/dQw4w9WgXcQ?start=42', true],
        'protocol-relative nocookie' => ['//www.youtube-nocookie.com/embed/dQw4w9WgXcQ', true],
        'no www' => ['https://youtube.com/embed/dQw4w9WgXcQ', true],
        'dot segments' => ['https://www.youtube.com/embed/../../redirect?q=https://evil.example.com/', false],
        'encoded dot segments' => ['https://www.youtube.com/embed/%2e%2e/%2e%2e/redirect?q=x', false],
        'encoded dot segments after id' => ['https://www.youtube.com/embed/dQw4w9WgXcQ/%2e%2e/%2e%2e/redirect', false],
        'videoseries' => ['https://www.youtube.com/embed/videoseries?list=PL0123456789', false],
        'short id' => ['https://www.youtube.com/embed/abc', false],
        'watch page' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', false],
        'other host' => ['https://www.youtube.com.evil.com/embed/dQw4w9WgXcQ', false],
        'videoseries in other case' => ['https://www.youtube.com/embed/VideoSeries?list=PL0123456789', false],
    ]);

    it('anchors the default pattern at the very end', function () {
        expect(preg_match(HtmlSanitizer::DEFAULT_IFRAME_REGEXP, "https://www.youtube.com/embed/dQw4w9WgXcQ\n"))->toBe(0)
            ->and(preg_match(HtmlSanitizer::DEFAULT_IFRAME_REGEXP, 'https://www.youtube.com/embed/dQw4w9WgXcQ'))->toBe(1)
            ->and(preg_match(HtmlSanitizer::DEFAULT_IFRAME_REGEXP, 'https://www.youtube.com/embed/VIDEOSERIES'))->toBe(0)
            ->and(config('brume.html.iframe_regexp'))->toBe(HtmlSanitizer::DEFAULT_IFRAME_REGEXP);
    });
});

describe('titles and reserved names', function () {
    it('keeps title on links and images', function () {
        $html = '<p><a href="/a" title="全部商品"><img src="/i.png" alt="" title="圖"></a></p>';

        expect(sanitizer()->clean($html))->toBe($html);
    });

    it('drops a reserved id, name or for, keeping the element', function (string $html, string $expected) {
        expect(sanitizer()->clean($html))->toBe($expected);
    })->with([
        ['<p id="submit">x</p>', '<p>x</p>'],
        ['<p id="ParentNode">x</p>', '<p>x</p>'],
        ['<p id="csrf-field">x</p>', '<p>x</p>'],
        ['<p id="__x">x</p>', '<p>x</p>'],
        ['<p id="_token">x</p>', '<p>x</p>'],
        ['<p id="hasOwnProperty">x</p>', '<p>x</p>'],
        ['<p><input type="checkbox" name="action"></p>', '<p><input type="checkbox"></p>'],
        ['<p><input type="checkbox" name="__proto__" id="toString"></p>', '<p><input type="checkbox"></p>'],
        ['<p><input type="radio" name="elements"><label for="getElementById">x</label></p>', '<p><input type="radio"><label>x</label></p>'],
    ]);

    it('keeps names that only contain a reserved word', function (string $html) {
        expect(sanitizer()->clean($html))->toBe($html);
    })->with([
        '<p id="submitted">x</p>',
        '<p id="mytoken">x</p>',
        '<p><input type="radio" name="faq-group" id="faq1"><label for="faq1">x</label></p>',
    ]);

    it('adds reserved_names from config to the defaults', function () {
        $sanitizer = sanitizer(['reserved_names' => ['cart', 'shop*']]);

        expect($sanitizer->clean('<p id="cart">a</p><p id="shopConfig">b</p><p id="submit">c</p><p id="carts">d</p>'))
            ->toBe('<p>a</p><p>b</p><p>c</p><p id="carts">d</p>');
    });
});

describe('input limits', function () {
    it('rejects parser floods before purify, quickly and without crashing', function (string $expression, ?int $maxBytes, string $reason) {
        // In a child process: these inputs used to crash PHP or exhaust its memory.
        [$exit, $result, $ms, $mb] = cleanInChild($expression, ['max_bytes' => $maxBytes]);

        expect($exit)->toBe(0)
            ->and($result)->toBe('rejected:'.$reason)
            ->and($ms)->toBeLessThan(1000.0)
            ->and($mb)->toBeLessThan(40.0)
            ->and((new HtmlRejected($reason, 1))->getMessage())->toContain('brume.html.max_'.$reason);
    })->with([
        'nested divs' => ["str_repeat('<div>', 50000).'x'.str_repeat('</div>', 50000)", 1_000_000, 'depth'],
        'flat elements' => ["str_repeat('<b>x</b>', 100000)", 1_000_000, 'elements'],
        'nested spans over the byte limit' => ["str_repeat('<span>', 100000).'x'.str_repeat('</span>', 100000)", 1_000_000, 'bytes'],
        'nested spans, no byte limit' => ["str_repeat('<span>', 100000).'x'.str_repeat('</span>', 100000)", null, 'depth'],
        'unclosed b' => ["str_repeat('<b>', 100000).'x'", 1_000_000, 'depth'],
    ]);

    it('measures what libxml builds, so scanner-confusing input cannot hide depth', function (string $kind) {
        $html = bypassShape($kind, 1000);
        $sanitizer = sanitizer(['max_bytes' => null]);

        expect($sanitizer->limitViolation($html))->toBe('depth')
            ->and(fn () => $sanitizer->clean($html))->toThrow(function (HtmlRejected $e) {
                expect($e->reason)->toBe('depth');
            });
    })->with(['quote', 'nul', 'pi', 'plain']);

    it('rejects the bypass shapes at 50 000 levels without crashing the process', function (string $kind) {
        // Run in a child process: before this fix these inputs segfaulted PHP.
        $code = 'require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).';'
            .'require '.var_export(__DIR__.'/bypass-shapes.php', true).';'
            .'$s = new Brume\Html\HtmlSanitizer(["cache_path" => false, "max_bytes" => null]);'
            .'$h = bypassShape('.var_export($kind, true).', 50000); $t = microtime(true);'
            .'try { $s->clean($h); echo "accepted"; } catch (Brume\Html\HtmlRejected $e) { echo "rejected:", $e->reason; }'
            .'echo " ", round(microtime(true) - $t, 3);';
        exec(escapeshellarg(PHP_BINARY).' -d memory_limit=512M -r '.escapeshellarg($code).' 2>&1', $output, $exit);

        expect($exit)->toBe(0)
            ->and($output[0] ?? '')->toStartWith('rejected:depth ')
            ->and((float) explode(' ', $output[0])[1])->toBeLessThan(2.0);
    })->with(['quote', 'nul', 'pi', 'plain']);

    it('allows max_elements and rejects one more', function () {
        $sanitizer = sanitizer(['max_elements' => 100]);
        $ok = str_repeat('<i>x</i>', 100);
        $over = str_repeat('<i>x</i>', 101);

        expect($sanitizer->clean($ok))->toBe($ok)
            ->and($sanitizer->limitViolation($over))->toBe('elements')
            ->and(fn () => $sanitizer->clean($over))->toThrow(function (HtmlRejected $e) {
                expect($e->reason)->toBe('elements')->and($e->limit)->toBe(100)
                    ->and($e->getMessage())->toContain('brume.html.max_elements');
            })
            ->and(sanitizer(['max_elements' => null])->limitViolation($over))->toBeNull()
            ->and(sanitizer(['max_elements' => 500])->limitViolation(str_repeat('<br>', 500)))->toBeNull()
            ->and(sanitizer(['max_elements' => 500])->limitViolation(str_repeat('<br>', 501)))->toBe('elements')
            ->and(HtmlSanitizer::DEFAULT_MAX_ELEMENTS)->toBe(20_000);
    });

    it('never returns a result that would be rejected when cleaned again', function () {
        // 3 elements in; HTMLPurifier reopens <b> around the block, so 5 come out. The projection
        // (7 here) refuses it below that, before purifying.
        $html = '<p><b>a<div>x</div>y</b></p>';
        $clean = '<p><b>a</b></p><div><b>x</b></div><b>y</b>';
        // Bytes can grow too: attribute values get quoted. 15 bytes in, 18 out.
        $quoted = '<p class=x>y</p>';

        expect(sanitizer()->treeStats($html))->toBe(['depth' => 3, 'elements' => 3, 'projected' => 7, 'bytes' => 56])
            ->and(sanitizer(['max_elements' => 7])->clean($html))->toBe($clean)
            ->and(sanitizer(['max_elements' => 7])->clean($clean))->toBe($clean)
            ->and(sanitizer(['max_elements' => 6])->limitViolation($html))->toBe('elements')
            ->and(sanitizer(['max_bytes' => 18])->clean($quoted))->toBe('<p class="x">y</p>')
            ->and(sanitizer(['max_bytes' => 17])->limitViolation($quoted))->toBe('bytes')
            ->and(fn () => sanitizer(['max_bytes' => 17])->clean($quoted))->toThrow(HtmlRejected::class);
    });

    it('accepts legacy lists and paragraphs that never close their tags', function () {
        $list = '<ul>'.str_repeat('<li>item', 300).'</ul>';
        $paragraphs = str_repeat('<p>para', 300);

        expect(sanitizer()->limitViolation($list))->toBeNull()
            ->and(sanitizer()->clean($list))->toBe('<ul>'.str_repeat('<li>item</li>', 300).'</ul>')
            ->and(sanitizer()->limitViolation($paragraphs))->toBeNull()
            ->and(sanitizer()->clean($paragraphs))->toBe(str_repeat('<p>para</p>', 300));
    });

    it('agrees with clean() on every input: clean throws exactly when limitViolation reports', function () {
        $parts = ['<div>', '</div>', '<b>', '</b>', '<p>', '<li>', '<ul>', '<table>', '<tr>', '<td>', '<a href="/x">', '</a>', '<span>',
            "<b x=a'b>", "'>", "<\0b>", '<?x ', '<b a=">">', '<!--', '-->', '<script>', '</script>', '<style>', 'x', '"', "'", '<', '>', '<br>', '<img src=x>'];
        $sanitizers = [
            sanitizer(['max_bytes' => 500, 'max_depth' => 8, 'max_elements' => 40]),
            sanitizer(['max_bytes' => null, 'max_depth' => null, 'max_elements' => 30]),
        ];
        $seen = ['bytes' => 0, 'depth' => 0, 'elements' => 0, 'none' => 0];
        mt_srand(424242);
        try {
            foreach (range(1, 2000) as $i) {
                $sanitizer = $sanitizers[$i % 2];
                $html = '';
                foreach (range(1, mt_rand(1, 120)) as $__) {
                    $html .= $parts[mt_rand(0, count($parts) - 1)];
                }
                $violation = $sanitizer->limitViolation($html);
                try {
                    $sanitizer->clean($html);
                    $thrown = null;
                } catch (HtmlRejected $e) {
                    $thrown = $e->reason;
                }

                expect($thrown)->toBe($violation);
                $seen[$violation ?? 'none']++;
            }
        } finally {
            mt_srand();
        }

        expect($seen['bytes'])->toBeGreaterThan(0)
            ->and($seen['depth'])->toBeGreaterThan(0)
            ->and($seen['elements'])->toBeGreaterThan(0)
            ->and($seen['none'])->toBeGreaterThan(0);
    });

    it('allows depth 250 and rejects 251', function () {
        $ok = str_repeat('<div>', 250).'x'.str_repeat('</div>', 250);
        $deep = str_repeat('<div>', 251).'x'.str_repeat('</div>', 251);

        expect(sanitizer()->clean($ok))->toBe($ok)
            ->and(sanitizer()->limitViolation($deep))->toBe('depth')
            ->and(fn () => sanitizer()->clean($deep))->toThrow(function (HtmlRejected $e) {
                expect($e->reason)->toBe('depth')->and($e->limit)->toBe(250);
            });
    });

    it('is exact at any max_depth, below, at and above the depth where libxml stops by itself', function (int $limit) {
        // libxml's bounded parse ends at 254 levels; from there on the deep parse must take over,
        // and nothing may ever be cut off silently.
        $nested = fn (int $levels) => str_repeat('<div>', $levels).'x'.str_repeat('</div>', $levels).'<p>after</p>';
        $sanitizer = sanitizer(['max_depth' => $limit]);

        expect($sanitizer->clean($nested($limit)))->toBe($nested($limit))
            ->and($sanitizer->limitViolation($nested($limit + 1)))->toBe('depth')
            ->and($sanitizer->limitViolation($nested($limit + 300)))->toBe('depth')
            // A stray </div> closes Brume's wrapper: the content then sits one level higher for libxml.
            ->and($sanitizer->clean('</div>'.$nested($limit)))->toBe($nested($limit))
            ->and($sanitizer->limitViolation('</div>'.$nested($limit + 1)))->toBe('depth');
    })->with([5, 250, 252, 253, 254, 255, 256, 257, 300]);

    it('knows where libxml stops without LIBXML_PARSEHUGE, and the default max_depth is below it', function () {
        expect(MeasuringLexer::nativeDepth())->toBeGreaterThan(HtmlSanitizer::DEFAULT_MAX_DEPTH);
    });

    it('refuses a deep document instead of losing its tail when a hook turns the deep parse off', function () {
        $hook = fn (HTMLPurifier_Config $config) => $config->set('Core.AllowParseManyTags', false);
        $nested = fn (int $levels) => str_repeat('<div>', $levels).'x'.str_repeat('</div>', $levels).'<p>after</p>';
        $sanitizer = sanitizer(['configure' => $hook, 'max_depth' => null]);

        expect($sanitizer->clean($nested(200)))->toBe($nested(200))
            ->and($sanitizer->limitViolation($nested(300)))->toBe('depth');
    });

    it('rejects end tags that close nothing under thousands of open elements without paying for them', function (string $expression) {
        // libxml searches every open element for each such end tag: 1–2 s for these inputs when
        // it is allowed to nest without limit. Its own depth limit ends the parse at once.
        [$exit, $result, $ms, $mb] = cleanInChild($expression);

        expect($exit)->toBe(0)
            ->and($result)->toBe('rejected:depth')
            ->and($ms)->toBeLessThan(300.0)
            ->and($mb)->toBeLessThan(20.0);
    })->with([
        "str_repeat('<i></b>', 42857)",
        "str_repeat('<span></p>', 30000)",
        "'<table>'.str_repeat('</td><div>x', 23802)",
        "str_repeat('<div>', 255).str_repeat('<b></i>', 42000)",
    ]);

    it('lets max_depth null allow any depth', function () {
        $html = str_repeat('<div>', 1000).'x'.str_repeat('</div>', 1000);

        expect(sanitizer(['max_depth' => null])->clean($html))->toBe($html);
    });

    it('measures depth on the tree libxml builds', function (string $html) {
        expect(sanitizer(['max_depth' => 5])->limitViolation($html))->toBeNull();
    })->with([
        // (not <wbr>: libxml's HTML 4 parser does not know it and nests it)
        'void elements' => str_repeat('<br><img src="/a.png" alt=""><hr><input type="checkbox"><col>', 300),
        'unclosed li, auto-closed by the parser' => '<ul>'.str_repeat('<li>item ', 300).'</ul>',
        'unclosed p, auto-closed by the parser' => str_repeat('<p>para ', 300),
        'closing tags floor at zero' => str_repeat('</div>', 1000).str_repeat('<div>', 5),
        'tags inside a comment' => '<!--'.str_repeat('<div>', 1000).'-->',
        'unterminated comment' => '<p>x</p><!--'.str_repeat('<div>', 1000),
        'not tags' => str_repeat('< div> <1> <> 3<4 a < b ', 1000),
    ]);

    it('counts the tag a > inside a quoted attribute does not end', function () {
        expect(sanitizer(['max_depth' => 2])->limitViolation('<div title="a>b"><div title=\'>\'><div>x</div></div></div>'))->toBe('depth')
            ->and(sanitizer(['max_depth' => 3])->limitViolation('<div title="a>b"><div title=\'>\'><div>x</div></div></div>'))->toBeNull();
    });

    it('rejects input over max_bytes, and max_bytes null disables it', function () {
        $html = '<p>'.str_repeat('a', 93).'</p>';

        expect(strlen($html))->toBe(100)
            ->and(sanitizer(['max_bytes' => 100])->clean($html))->toBe($html)
            ->and(sanitizer(['max_bytes' => 99])->limitViolation($html))->toBe('bytes')
            ->and(fn () => sanitizer(['max_bytes' => 99])->clean($html))->toThrow(function (HtmlRejected $e) {
                expect($e->reason)->toBe('bytes')->and($e->limit)->toBe(99);
            })
            // limitViolation() cleans: keep this small, the suite's process is near 128 MB by now.
            ->and(sanitizer(['max_bytes' => null])->limitViolation(str_repeat('a', 400_000)))->toBeNull()
            ->and(sanitizer()->limitViolation(null))->toBeNull()
            ->and(sanitizer()->limitViolation('<p>x</p>'))->toBeNull();
    });

    it('prepares links in linear time on 1 MB of garbage', function (string $kind) {
        $size = 1_000_000;
        $html = match ($kind) {
            'random bytes' => random_bytes($size),
            'open brackets' => str_repeat('<', $size),
            'unterminated quotes' => str_repeat('<a "', intdiv($size, 4)),
            'unterminated single quotes' => str_repeat("<a b='", intdiv($size, 6)),
            'comment openers' => str_repeat('<!--', intdiv($size, 4)),
            'bogus comments' => str_repeat('<!x', intdiv($size, 3)),
            'tags' => str_repeat('<b></b>', intdiv($size, 7)),
            'links' => str_repeat('<a href="/x">', intdiv($size, 13)),
        };
        $start = microtime(true);

        TagScanner::prepareLinks($html, TagScanner::aliasFor($html));

        expect(microtime(true) - $start)->toBeLessThan(0.5);
    })->with(['random bytes', 'open brackets', 'unterminated quotes', 'unterminated single quotes', 'comment openers', 'bogus comments', 'tags', 'links']);

    it('cleans a 900 KB realistic document when the limits allow it', function () {
        $fixtures = implode('', array_map(htmlFixture(...), legacyHtmlFixtures()));
        $html = str_repeat($fixtures, (int) ceil(900_000 / strlen($fixtures)));
        $sanitizer = sanitizer(['max_bytes' => 1_000_000, 'max_elements' => null]);
        $sanitizer->clean('<p>warm</p>');
        // The suite's process has grown by now; the budget asserted below is what cleaning adds.
        $limit = ini_set('memory_limit', '1G');
        try {
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $start = microtime(true);

            $clean = $sanitizer->clean($html);

            $seconds = microtime(true) - $start;
            $megabytes = (memory_get_peak_usage() - $before) / 1_048_576;
        } finally {
            // Only when it fits: late in the whole suite the process alone can be over 128 MB.
            if ($limit !== false && memory_get_usage(true) < ini_parse_quantity($limit)) {
                ini_set('memory_limit', $limit);
            }
        }
        expect(strlen($html))->toBeLessThan(1_000_000)
            ->and($clean)->toContain('冬季禮盒')
            ->and($seconds)->toBeLessThan(5.0)
            ->and($megabytes)->toBeLessThan(128.0);
    });
});

it('is idempotent, safe and never nests links on random tag soup', function () {
    $parts = ['<div>', '</div>', '<a href="/x">', '</a>', '<A HREF="/y">', '<a>', '<p>', '</p>', '<table>', '<tr>', '<td>', '</td>', '</table>',
        '<ul>', '<li>', '<b>', '</b>', '<h2>', '</h2>', '<span>', '</span>', '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ">', '</iframe>',
        '<input type=checkbox>', '<label>', '</label>', 'x', '&nbsp;', '<!--', '-->', "\xC0", '"', "'", '<', '>', '<img src=x alt="a>b">',
        '<span style="top:calc(1px)">', '<svg>', '<style>', '<script>', '</script>', '<noscript>', '<textarea>', '<brumelink>', '<a/>', '<a title=">">'];
    $sanitizer = sanitizer();
    mt_srand(20261123);
    try {
        foreach (range(1, 1500) as $_) {
            $html = '';
            foreach (range(1, mt_rand(1, 30)) as $__) {
                $html .= $parts[mt_rand(0, count($parts) - 1)];
            }
            $once = $sanitizer->clean($html);

            expect($sanitizer->clean((string) $once))->toBe($once);
            if ($once !== null) {
                expect($once)->not->toMatch('/<(script|style|svg|noscript|textarea)|\son\w+=|<brumelink|brumea[0-9a-f]{24}/i')
                    ->not->toMatch('/<a\b[^>]*>(?:(?!<\/a>).)*<a\b/s');
            }
        }
    } finally {
        mt_srand();
    }
});
