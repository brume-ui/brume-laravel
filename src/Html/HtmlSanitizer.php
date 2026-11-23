<?php

namespace Brume\Html;

use Closure;
use ErrorException;
use HTMLPurifier;
use HTMLPurifier_AttrDef;
use HTMLPurifier_AttrDef_CSS_AlphaValue;
use HTMLPurifier_AttrDef_CSS_ImportantDecorator;
use HTMLPurifier_AttrDef_Enum;
use HTMLPurifier_AttrDef_HTML_ID;
use HTMLPurifier_AttrTransform_ImgRequired;
use HTMLPurifier_Config;
use ReflectionFunction;
use Throwable;

/**
 * Server-side whitelist for rich text HTML (spec §4, "放寬，不含 position:fixed").
 *
 * Its output is printed raw on public pages, so this is the security boundary: every write and
 * every read of a rich text value goes through {@see self::clean()}. Legacy content that is
 * already inside the whitelist must come back unchanged (up to serialisation), and nothing may
 * be added to it.
 */
final class HtmlSanitizer
{
    /** Bump when the definition built below changes, so cached definitions are rebuilt. */
    private const SCHEMA = 1;

    private const ELEMENTS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol', 'li',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'colgroup', 'col',
        'img', 'a', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'sup', 'sub',
        'span', 'div', 'article', 'blockquote', 'hr', 'iframe', 'input', 'label',
    ];

    private const ATTRIBUTES = [
        '*.class', '*.style', '*.id',
        'a.href', 'a.target', 'a.rel',
        'img.src', 'img.alt', 'img.width', 'img.height', 'img.loading',
        'th.colspan', 'th.rowspan', 'td.colspan', 'td.rowspan', 'td.data-th',
        'table.border', 'table.cellpadding', 'table.cellspacing', 'table.width',
        'ol.start', 'ol.type', 'col.span', 'col.width',
        'iframe.src', 'iframe.width', 'iframe.height', 'iframe.allowfullscreen', 'iframe.frameborder', 'iframe.title', 'iframe.loading',
        'input.type', 'input.name', 'input.checked', 'label.for',
    ];

    /** Properties HTMLPurifier knows; CSS3 ones are added in {@see self::addCssProperties()}. */
    private const CSS_PROPERTIES = [
        'text-align', 'color', 'background-color', 'width', 'height', 'max-width', 'min-width', 'max-height', 'min-height',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-width', 'border-style', 'border-color', 'border-collapse', 'border-spacing',
        'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-top-width', 'border-top-style', 'border-top-color', 'border-right-width', 'border-right-style', 'border-right-color',
        'border-bottom-width', 'border-bottom-style', 'border-bottom-color', 'border-left-width', 'border-left-style', 'border-left-color',
        'font-weight', 'font-style', 'text-decoration', 'vertical-align',
        'font-size', 'font-family', 'line-height', 'letter-spacing', 'text-indent', 'white-space',
        'list-style', 'list-style-type', 'list-style-position',
        'float', 'clear',
        'background', 'background-image', 'background-size', 'background-repeat',
        'border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-left-radius', 'border-bottom-right-radius',
    ];

    /** Ids that would shadow globals scripts commonly read (DOM clobbering). */
    private const ID_BLACKLIST = '/^(?:__|(?:app|admin|csrf|token|login|password|submit|action|form|document|location|window|name|cookie)$)/i';

    public const DEFAULT_IFRAME_REGEXP = '%^(?:https?:)?//(?:www\.)?youtube(?:-nocookie)?\.com/embed/%';

    private bool $allowFixed;

    /** @var list<string>|null */
    private ?array $allowedClasses;

    private ?string $iframeRegexp;

    private string|false $cachePath;

    /** @var (callable(HTMLPurifier_Config): void)|null */
    private $configure;

    private ?string $definitionId = null;

    private ?HTMLPurifier $purifier = null;

    /**
     * @param  array{allow_fixed?: bool, allowed_classes?: list<string>|null, iframe_regexp?: string|null, cache_path?: string|false|null, configure?: (callable(HTMLPurifier_Config): void)|null}  $options
     */
    public function __construct(array $options = [])
    {
        $this->allowFixed = (bool) ($options['allow_fixed'] ?? false);
        $classes = $options['allowed_classes'] ?? null;
        $this->allowedClasses = $classes === null ? null : array_values(array_map('strval', $classes));
        $this->iframeRegexp = array_key_exists('iframe_regexp', $options) ? ($options['iframe_regexp'] ?: null) : self::DEFAULT_IFRAME_REGEXP;
        $cachePath = $options['cache_path'] ?? null;
        $this->cachePath = $cachePath === false ? false : (string) ($cachePath ?? storage_path('framework/cache/brume-htmlpurifier'));
        $this->configure = $options['configure'] ?? null;
    }

    /** Sanitised HTML, or null when the input is null or nothing but empty markup and whitespace. */
    public function clean(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }
        $clean = $this->purifier()->purify($html);

        return self::isEmpty($clean) ? null : $clean;
    }

    /**
     * True when the HTML carries no content. Same rule as `isEmptyHtml` in brume-react, step by
     * step, on bytes (no `/u`, no `\s`):
     *
     *  a. remove comments `<!--` … `-->` (shortest match, across lines);
     *  b. a `<` followed, case-insensitively, by img, iframe, hr, table, input, video, audio, svg,
     *     object or embed and then by space, tab, LF, CR, FF, `/` or `>` is content;
     *  c. remove tags matching `<[a-zA-Z/!?][^>]*>`;
     *  d. remove ASCII space, \t, \n, \r, \f, \v, U+00A0 and the entities `&nbsp;` (lowercase),
     *     `&#160;`, `&#xA0;` (x and hex digit in either case);
     *  e. empty iff nothing remains.
     */
    public static function isEmpty(?string $html): bool
    {
        if ($html === null || $html === '') {
            return true;
        }
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        if (preg_match('/<(?:img|iframe|hr|table|input|video|audio|svg|object|embed)[ \t\n\r\f\/>]/i', $html) === 1) {
            return false;
        }
        $html = preg_replace('/<[a-zA-Z\/!?][^>]*>/', '', $html);

        return preg_replace('/[ \t\n\r\f\v]|\xC2\xA0|&nbsp;|&#160;|&#[xX][aA]0;/', '', $html) === '';
    }

    /**
     * HTML.DefinitionID used for the cached definition: a hash of every option that shapes it,
     * so a changed option can never be served a stale cached definition.
     *
     * @internal
     */
    public function definitionId(): string
    {
        return $this->definitionId ??= 'brume-'.substr(hash('sha256', serialize([
            self::SCHEMA,
            $this->allowFixed,
            $this->allowedClasses,
            $this->iframeRegexp,
            $this->configure === null ? null : self::callableFingerprint($this->configure),
        ])), 0, 24);
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }
        $cacheDir = $this->usableCacheDir();
        try {
            $config = $this->buildConfig($cacheDir);
        } catch (ErrorException $e) {
            // A cache directory that turned unusable mid-build: HTMLPurifier reports it with
            // trigger_error, which Laravel turns into an exception. Retry without the cache; any
            // other problem raises again from the second build.
            if ($cacheDir === null) {
                throw $e;
            }
            $config = $this->buildConfig(null);
        }

        return $this->purifier = new HTMLPurifier($config);
    }

    private function usableCacheDir(): ?string
    {
        if ($this->cachePath === false || $this->cachePath === '') {
            return null;
        }
        if (! is_dir($this->cachePath)) {
            try {
                @mkdir($this->cachePath, 0775, true);
            } catch (Throwable) {
                // An error handler that ignores @ still must not break sanitising.
            }
        }

        return is_dir($this->cachePath) && is_writable($this->cachePath) ? $this->cachePath : null;
    }

    private function buildConfig(?string $cacheDir): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        // get() would finalise the config, so the configure hook could not read a directive and
        // then set it. It is finalised explicitly once the raw definition is done.
        $config->autoFinalize = false;
        // Every set() must happen before maybeGetRawHTMLDefinition().
        $config->set('Core.Encoding', 'UTF-8');
        // libxml stops at nesting depth 256 and silently drops the rest of the document; with
        // this flag it parses deeper (cost stays linear: 20 000 levels ≈ 0.1 s).
        $config->set('Core.AllowParseManyTags', true);
        // HTML 4.01 serialises void elements as <br>, <img …> and boolean attributes minimised.
        // Transitional keeps u, s, target, table border/cellpadding, ol type.
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.TidyLevel', 'none');
        $config->set('HTML.AllowedElements', self::ELEMENTS);
        $config->set('HTML.AllowedAttributes', self::ATTRIBUTES);
        // Not clamped: legacy images wider than HTMLPurifier's 1200px default must keep their size.
        $config->set('HTML.MaxImgLength', null);
        $config->set('CSS.MaxImgLength', null);

        $config->set('Attr.EnableID', true);
        $config->set('Attr.ID.HTML5', true);
        $config->set('Attr.IDBlacklistRegexp', self::ID_BLACKLIST);
        $config->set('Attr.AllowedClasses', $this->allowedClasses);
        $config->set('Attr.AllowedFrameTargets', ['_blank', 'blank']);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);
        // Both default to true and would add rel to every target=_blank link.
        $config->set('HTML.TargetNoreferrer', false);
        $config->set('HTML.TargetNoopener', false);

        $config->set('CSS.AllowedProperties', self::CSS_PROPERTIES);
        // border-radius lives in the proprietary set. Not CSS.AllowTricky / CSS.Trusted: they
        // would allow more than the whitelist (position:fixed, visibility, …).
        $config->set('CSS.Proprietary', true);

        // Relative URLs are always allowed. mailto/tel are not "browsable", so HTMLPurifier
        // refuses them in src and url() and they only survive in href.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $config->set('HTML.SafeIframe', true);
        $config->set('URI.SafeIframeRegexp', $this->iframeRegexp);

        if ($cacheDir === null) {
            $config->set('Cache.DefinitionImpl', null);
        } else {
            $config->set('Cache.SerializerPath', $cacheDir);
        }
        $config->set('HTML.DefinitionID', $this->definitionId());
        $config->set('HTML.DefinitionRev', (int) hexdec(substr(hash('crc32b', $this->definitionId()), 0, 7)));

        if ($this->configure !== null) {
            ($this->configure)($config);
        }

        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $defined = $def->getAnonymousModule()->info;
            $def->addAttribute('iframe', 'allowfullscreen', 'Bool');
            $def->addAttribute('iframe', 'loading', 'Enum#lazy,eager');
            $def->addAttribute('img', 'loading', 'Enum#lazy,eager');
            $def->addAttribute('td', 'data-th', 'Text');
            if (! isset($defined['article'])) {
                $def->addElement('article', 'Block', 'Flow', 'Common');
            }
            if (! isset($defined['input'])) {
                // CSS-only accordions. type is required; nothing here can submit or take text input.
                $def->addElement('input', 'Inline', 'Empty', 'Common', ['type*' => 'Enum#checkbox,radio', 'name' => 'Text', 'checked' => 'Bool']);
            }
            if (! isset($defined['label'])) {
                $def->addElement('label', 'Inline', 'Inline', 'Common', ['for' => new HTMLPurifier_AttrDef_HTML_ID(true)]);
            }
            // HTML5 lets a link wrap blocks (legacy cards do); HTML 4 would split the link apart.
            $a = $def->addBlankElement('a');
            $a->content_model = '#SUPER | Block';
            $a->content_model_type = 'optional';
        }
        $config->finalize();

        $html = $config->getHTMLDefinition();
        // Not cached by HTMLPurifier, so applied on every build:
        // img would get alt="<file name>" injected and src defaulted; only src is required here.
        if (isset($html->info['img'])) {
            $img = $html->info['img'];
            $keep = fn (array $transforms) => array_filter($transforms, fn ($t) => ! $t instanceof HTMLPurifier_AttrTransform_ImgRequired);
            $img->attr_transform_pre = $keep($img->attr_transform_pre);
            $img->attr_transform_post = $keep($img->attr_transform_post);
            $img->required_attr = ['src'];
        }
        // An iframe whose src was rejected would otherwise stay as an empty shell.
        if (isset($html->info['iframe'])) {
            $html->info['iframe']->required_attr = ['src'];
        }

        $this->addCssProperties($config);

        return $config;
    }

    /**
     * HTMLPurifier 4.19 has no raw CSS definition hook, and the serialised cache only holds the
     * stock part, so the CSS3 properties are added to the finished definition on every build.
     * They are wrapped like the stock ones (`!important` dropped, value kept) and respect
     * %CSS.ForbiddenProperties, so the configure hook can still remove them.
     */
    private function addCssProperties(HTMLPurifier_Config $config): void
    {
        $css = $config->getCSSDefinition();
        $forbidden = $config->get('CSS.ForbiddenProperties');
        $important = $config->get('CSS.AllowImportant');

        $enum = fn (array $values) => new HTMLPurifier_AttrDef_Enum($values);
        $length = '-?(?:\d+|\d*\.\d+)(?:px|em|rem|%|vw|vh|vmin|vmax|pt|ch)?';
        // No url(), quotes or backslashes can pass these.
        $fn = '(?:calc|clamp|min|max)\([0-9a-z.%+\-*\/ ,]+\)';
        $lenOrFn = "(?:$length|$fn|auto)";
        $num = '(?:\d+|\d*\.\d+)';
        $color = '(?:#[0-9a-f]{3,8}|rgba?\([0-9., %]+\)|[a-z]+)';
        $shadow = "(?:inset )?(?:$length ){1,3}$length(?: $color)?";
        $position = '(?:left|right|top|bottom|center|'.$length.')';
        $regex = fn (string $body) => new RegexAttrDef('/^(?:'.$body.')\z/');

        $add = [
            'display' => $enum(['inline', 'block', 'inline-block', 'flex', 'inline-flex', 'grid', 'list-item', 'table', 'table-row', 'table-cell', 'none']),
            'justify-content' => $enum(['flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'space-evenly', 'start', 'end', 'left', 'right', 'normal', 'stretch']),
            'align-items' => $enum(['flex-start', 'flex-end', 'center', 'baseline', 'stretch', 'start', 'end', 'normal', 'auto']),
            'align-self' => $enum(['flex-start', 'flex-end', 'center', 'baseline', 'stretch', 'start', 'end', 'normal', 'auto']),
            'flex-direction' => $enum(['row', 'row-reverse', 'column', 'column-reverse']),
            'flex-wrap' => $enum(['nowrap', 'wrap', 'wrap-reverse']),
            'flex-grow' => $regex($num),
            'flex-shrink' => $regex($num),
            'flex-basis' => $regex($lenOrFn),
            'flex' => $regex("none|auto|$num(?: $num)?(?: $lenOrFn)?|$lenOrFn"),
            'gap' => $regex("$lenOrFn(?: $lenOrFn)?"),
            'box-sizing' => $enum(['border-box', 'content-box']),
            'box-shadow' => $regex("none|$shadow(?:, ?$shadow)*"),
            'object-fit' => $enum(['contain', 'cover', 'fill', 'none', 'scale-down']),
            // No `fixed` unless enabled: it would overlay the whole storefront page.
            'position' => $enum(['static', 'relative', 'absolute', 'sticky', ...($this->allowFixed ? ['fixed'] : [])]),
            'top' => $regex($lenOrFn),
            'right' => $regex($lenOrFn),
            'bottom' => $regex($lenOrFn),
            'left' => $regex($lenOrFn),
            'z-index' => $regex('auto|-?\d{1,4}'),
            'outline' => $regex("none|0|$length(?: (?:none|solid|dashed|dotted|double))?(?: $color)?"),
            // Kept verbatim: HTMLPurifier's own def rewrites `center center` to `center`.
            'background-position' => $regex("$position(?: $position){0,3}"),
            'background-attachment' => $enum(['scroll', 'fixed', 'local', 'initial']),
            'background-origin' => $enum(['border-box', 'padding-box', 'content-box', 'initial']),
            'background-clip' => $enum(['border-box', 'padding-box', 'content-box', 'initial']),
            'padding-inline-start' => $regex($lenOrFn),
            'overflow' => $enum(['visible', 'hidden', 'auto', 'scroll']),
            'opacity' => new HTMLPurifier_AttrDef_CSS_AlphaValue,
        ];
        foreach ($add as $property => $def) {
            if (isset($forbidden[$property])) {
                continue;
            }
            /** @var HTMLPurifier_AttrDef $def */
            $css->info[$property] = new HTMLPurifier_AttrDef_CSS_ImportantDecorator($def, $important);
        }
    }

    /** A stable identity for the configure hook: where it is defined and when that file changed. */
    private static function callableFingerprint(callable $callable): string
    {
        try {
            $reflection = new ReflectionFunction(Closure::fromCallable($callable));
            $file = $reflection->getFileName();
            $scope = $reflection->getClosureScopeClass()?->getName();

            return implode('|', [
                $scope.'::'.$reflection->getName(),
                $file,
                $reflection->getStartLine(),
                $reflection->getEndLine(),
                $file !== false ? @filemtime($file) : '',
            ]);
        } catch (Throwable) {
            return is_object($callable) ? $callable::class : serialize($callable);
        }
    }
}
