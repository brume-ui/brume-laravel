<?php

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
    // `title` is not on the spec's attribute whitelist (only iframe[title] is).
    '04-link-blocks.html' => [' title="全部商品"' => ''],
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

it('ships the twelve hand-written fixtures and the hostile one', function () {
    expect(legacyHtmlFixtures())->toHaveCount(12)
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
        'td' => ['colspan', 'rowspan', 'data-th'], 'colgroup' => [], 'col' => ['span', 'width'], 'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'a' => ['href', 'target', 'rel'], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'sup' => [], 'sub' => [],
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

    it('survives 500 nested divs in bounded time, losing nothing after them', function () {
        $html = '<p>before</p>'.str_repeat('<div>', 500).'deep<script>alert(1)</script>'.str_repeat('</div>', 500).'<p>after</p>';
        $start = microtime(true);

        $clean = sanitizer()->clean($html);

        expect(microtime(true) - $start)->toBeLessThan(5.0)
            ->and($clean)->toContain('<p>before</p>')
            ->toContain('deep')
            ->toContain('<p>after</p>')
            ->not->toContain('script')
            ->and(substr_count($clean, '<div>'))->toBe(500);
    });

    it('drops invalid UTF-8 bytes without raising', function () {
        throwingErrorHandler();
        try {
            $clean = sanitizer()->clean("<p>ok\xC3\x28 \xFF\xFE bytes</p><p>中文</p>");
        } finally {
            restore_error_handler();
        }

        // HTMLPurifier drops an invalid sequence together with the byte that broke it.
        expect($clean)->toBe('<p>ok  bytes</p><p>中文</p>')
            ->and(mb_check_encoding($clean, 'UTF-8'))->toBeTrue();
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
        '<p><iframe src="//www.youtube.com/embed/abc" title="video" loading="lazy"></iframe></p>',
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
        ->and(config('brume.html.iframe_regexp'))->toBe('%^(?:https?:)?//(?:www\.)?youtube(?:-nocookie)?\.com/embed/%');
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
