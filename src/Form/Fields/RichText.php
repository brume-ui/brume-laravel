<?php

namespace Brume\Form\Fields;

use Brume\Html\HtmlSanitizer;
use Brume\Support\Href;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Stringable;
use WeakMap;

/**
 * An HTML document edited with the rich text editor. Every value is sanitised on the server:
 * fill() cleans before any writer (including saveUsing) sees it, and normalizeValue() cleans
 * whatever is read back (stored row, JSON row, relation row, value() reader, default) before it
 * reaches the browser.
 *
 * Validation runs on the submitted HTML. A limit rule (brume.html.max_bytes / max_depth) always
 * applies; FormValidator shows the validator an empty document as null (see isEmptyDocument()),
 * so required, required_if, required_with and friends behave natively.
 *
 * A stored value over the limits is never parsed: it reads as null (with a log warning) and is
 * only replaced when a non-empty new value is saved.
 */
class RichText extends Field
{
    public const DEFAULT_ACCEPT = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    protected ?string $diskName = null;

    protected ?string $directoryName = null;

    protected array $accept = self::DEFAULT_ACCEPT;

    protected ?int $maxBytes = null;

    protected bool $images = true;

    public function type(): string
    {
        return 'richText';
    }

    public function height(int $pixels): static
    {
        if ($pixels < 120 || $pixels > 2000) {
            throw new InvalidArgumentException('Editor height must be between 120 and 2000.');
        }
        $this->schema['height'] = $pixels;

        return $this;
    }

    /** Stylesheets loaded inside the editing area; blank entries are dropped. */
    public function contentCss(string|array $urls): static
    {
        $urls = array_values(array_filter(array_map(fn ($url) => is_string($url) ? trim($url) : $url, (array) $urls), fn ($url) => $url !== ''));
        foreach ($urls as $url) {
            if (! is_string($url)) {
                throw new InvalidArgumentException('Editor stylesheets must be URL strings.');
            }
            if (Href::safe($url) === null || preg_match('/^(?:mailto|tel):/i', (string) preg_replace('/[\x00-\x20\x7F]+/', '', $url)) === 1) {
                throw new InvalidArgumentException("RichText::contentCss 的網址「{$url}」用了不允許的協定：只能是 http、https 或相對網址。");
            }
        }
        if ($urls === []) {
            unset($this->schema['contentCss']);
        } else {
            $this->schema['contentCss'] = $urls;
        }

        return $this;
    }

    public function disk(string $disk): static
    {
        $this->diskName = $disk;

        return $this;
    }

    public function directory(string $directory): static
    {
        $this->directoryName = $directory;

        return $this;
    }

    /** Raster image MIME types accepted for pasted or dropped images. SVG is never accepted. */
    public function accept(array $mimes): static
    {
        if ($mimes === []) {
            throw new InvalidArgumentException('Editor image types cannot be empty; use withoutImages() to disable images.');
        }
        $accept = [];
        foreach ($mimes as $mime) {
            $normalized = is_string($mime) ? strtolower(trim($mime)) : '';
            if (! preg_match('~^image/[a-z0-9][a-z0-9!#$&^_.+-]*\z~', $normalized) || str_contains($normalized, 'svg')) {
                throw new InvalidArgumentException('Editor images must be raster image types; SVG is never accepted.');
            }
            $accept[] = $normalized;
        }
        $this->accept = array_values(array_unique($accept));

        return $this;
    }

    public function maxSize(int $kilobytes): static
    {
        if ($kilobytes < 1) {
            throw new InvalidArgumentException('Max upload size must be positive.');
        }
        $this->maxBytes = $kilobytes * 1024;

        return $this;
    }

    /** Disables pasted and dropped image uploads; inserting an image by URL stays possible. */
    public function withoutImages(): static
    {
        $this->images = false;

        return $this;
    }

    public function attributes(array $attributes): static
    {
        throw new InvalidArgumentException('Rich text fields store one HTML string and cannot map attributes.');
    }

    public function diskName(): string
    {
        return $this->diskName ?? config('brume.uploads.disk', 'public');
    }

    public function directoryFor(string $formId): string
    {
        return $this->directoryName ?? 'brume/'.$formId.'/editor';
    }

    public function acceptedMimes(): array
    {
        return $this->accept;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes ?? (int) config('brume.uploads.max_size', 10240) * 1024;
    }

    public function imagesEnabled(): bool
    {
        return $this->images;
    }

    public function toSchema(?Model $record = null): array
    {
        return parent::toSchema($record) + ($this->images ? ['images' => ['accept' => $this->acceptedMimes(), 'maxSize' => $this->maxBytes()]] : []);
    }

    public function impliedRules(): array
    {
        return ['string', ...parent::impliedRules()];
    }

    public function serverRules(?Model $record = null, ?Model $model = null, bool $includeUnique = true): array
    {
        return [...parent::serverRules($record, $model, $includeUnique), self::limitRule()];
    }

    /** Over max_bytes / max_depth fails with a translated message before anything parses it. */
    public static function limitRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $violation = is_string($value) ? app(HtmlSanitizer::class)->limitViolation($value) : null;
            if ($violation !== null) {
                $fail('brume::form.rich_text_'.$violation)->translate();
            }
        };
    }

    /**
     * What the validator sees as "no value": an empty-looking document (HtmlSanitizer::isEmpty,
     * the client's isEmptyHtml rule) or one that sanitises to nothing (a lone script, say).
     * Over-limit input is never empty here, so it reaches the limit rule unparsed.
     */
    public static function isEmptyDocument(string $html): bool
    {
        if (app(HtmlSanitizer::class)->limitViolation($html) !== null) {
            return false;
        }

        return HtmlSanitizer::isEmpty($html) || self::sanitize($html) === null;
    }

    /**
     * The single write sink: root, JSON rows, relation rows and saveUsing all receive clean HTML.
     * An empty value never overwrites a stored value over the limits (it was sent to the browser
     * as null, so null means "untouched"); only non-empty new content replaces it.
     */
    public function fill(Model $record, mixed $value): void
    {
        $clean = self::sanitize(self::string($value));
        if ($clean === null && $this->writer === null) {
            $stored = data_get($record, $this->attributeName());
            if (is_string($stored) && app(HtmlSanitizer::class)->limitViolation($stored) !== null) {
                return;
            }
        }
        parent::fill($record, $clean);
    }

    protected function normalizeValue(mixed $value): mixed
    {
        $value = self::string(parent::normalizeValue($value));
        $violation = $value === null ? null : app(HtmlSanitizer::class)->limitViolation($value);
        if ($violation !== null) {
            Log::warning('Brume rich text value over the brume.html limits was not sent to the editor.', ['field' => $this->path(), 'reason' => $violation]);

            return null;
        }

        return self::sanitize($value);
    }

    private static function string(mixed $value): ?string
    {
        if ($value instanceof Stringable || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) ? $value : null;
    }

    /** @var WeakMap<HtmlSanitizer, array<string, ?string>>|null */
    private static ?WeakMap $memo = null;

    /**
     * HtmlSanitizer::clean() memoised per sanitiser instance (one instance = one configuration),
     * so a value read, validated and saved in one request is purified once. Small and bounded;
     * keyed by sha256 of the input. Over-limit input throws HtmlRejected and is never stored.
     */
    private static function sanitize(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }
        $sanitizer = app(HtmlSanitizer::class);
        self::$memo ??= new WeakMap;
        $entries = self::$memo[$sanitizer] ?? [];
        $key = hash('sha256', $html);
        if (array_key_exists($key, $entries)) {
            return $entries[$key];
        }
        $clean = $sanitizer->clean($html);
        if (count($entries) >= 16) {
            array_shift($entries);
        }
        $entries[$key] = $clean;
        self::$memo[$sanitizer] = $entries;

        return $clean;
    }
}
