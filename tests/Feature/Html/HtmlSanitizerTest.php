<?php

use Brume\Html\HtmlRejected;
use Brume\Html\HtmlSanitizer;
use Brume\Tests\Support\HtmlNormalizer;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\File;

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

dataset('legacy fixtures', fn () => legacyHtmlFixtures());

it('ships the hand-written fixtures (twelve from brume-ui, links around blocks) and the hostile one', function () {
    expect(legacyHtmlFixtures())->toHaveCount(13)
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
        ->and(config('brume.html.max_depth'))->toBe(256)
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
        ['<brumelink href="/x">alias spelt out</brumelink>', '<a href="/x">alias spelt out</a>'],
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
    ]);
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
    it('rejects parser floods before purify, quickly and without crashing', function (string $kind, ?int $maxBytes, string $reason) {
        $html = match ($kind) {
            'divs' => str_repeat('<div>', 50_000).'x'.str_repeat('</div>', 50_000),
            'spans' => str_repeat('<span>', 100_000).'x'.str_repeat('</span>', 100_000),
            'unclosed b' => str_repeat('<b>', 100_000).'x',
        };
        $start = microtime(true);

        expect(fn () => sanitizer(['max_bytes' => $maxBytes])->clean($html))
            ->toThrow(function (HtmlRejected $e) use ($reason) {
                expect($e->reason)->toBe($reason)
                    ->and($e->getMessage())->toContain('brume.html.max_'.$reason);
            })
            ->and(microtime(true) - $start)->toBeLessThan(1.0);
    })->with([
        ['divs', 1_000_000, 'depth'],
        ['spans', 1_000_000, 'bytes'],
        ['spans', null, 'depth'],
        ['unclosed b', 1_000_000, 'depth'],
    ]);

    it('allows depth 256 and rejects 257', function () {
        $ok = str_repeat('<div>', 256).'x'.str_repeat('</div>', 256);
        $deep = str_repeat('<div>', 257).'x'.str_repeat('</div>', 257);

        expect(sanitizer()->clean($ok))->toBe($ok)
            ->and(sanitizer()->limitViolation($deep))->toBe('depth')
            ->and(fn () => sanitizer()->clean($deep))->toThrow(function (HtmlRejected $e) {
                expect($e->reason)->toBe('depth')->and($e->limit)->toBe(256);
            });
    });

    it('lets max_depth null allow any depth', function () {
        $html = str_repeat('<div>', 1000).'x'.str_repeat('</div>', 1000);

        expect(sanitizer(['max_depth' => null])->clean($html))->toBe($html);
    });

    it('counts only open non-void elements', function (string $html) {
        expect(sanitizer(['max_depth' => 5])->limitViolation($html))->toBeNull();
    })->with([
        'void and self-closed' => str_repeat('<br><img src="/a.png" alt=""><hr><input type="checkbox"><col><wbr><span/><DIV />', 300),
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
            ->and(sanitizer(['max_bytes' => null])->limitViolation(str_repeat('a', 2_000_000)))->toBeNull()
            ->and(sanitizer()->limitViolation(null))->toBeNull()
            ->and(sanitizer()->limitViolation('<p>x</p>'))->toBeNull();
    });

    it('scans 5 MB of garbage in linear time', function (string $kind) {
        $size = 5_000_000;
        $html = match ($kind) {
            'random bytes' => random_bytes($size),
            'open brackets' => str_repeat('<', $size),
            'unterminated quotes' => str_repeat('<a "', intdiv($size, 4)),
            'unterminated single quotes' => str_repeat("<a b='", intdiv($size, 6)),
            'comment openers' => str_repeat('<!--', intdiv($size, 4)),
            'bogus comments' => str_repeat('<!x', intdiv($size, 3)),
            'tags' => str_repeat('<b></b>', intdiv($size, 7)),
        };
        // A depth limit that is never reached forces a scan of every byte.
        $sanitizer = sanitizer(['max_bytes' => null, 'max_depth' => PHP_INT_MAX]);
        $start = microtime(true);

        expect($sanitizer->limitViolation($html))->toBeNull();

        expect(microtime(true) - $start)->toBeLessThan(2.0);
    })->with(['random bytes', 'open brackets', 'unterminated quotes', 'unterminated single quotes', 'comment openers', 'bogus comments', 'tags']);

    it('cleans a 900 KB realistic document', function () {
        $fixtures = implode('', array_map(htmlFixture(...), legacyHtmlFixtures()));
        $html = str_repeat($fixtures, (int) ceil(900_000 / strlen($fixtures)));
        $sanitizer = sanitizer();
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
                expect($once)->not->toMatch('/<(script|style|svg|noscript|textarea|brumelink)|\son\w+=/i')
                    ->not->toMatch('/<a\b[^>]*>(?:(?!<\/a>).)*<a\b/s');
            }
        }
    } finally {
        mt_srand();
    }
});
