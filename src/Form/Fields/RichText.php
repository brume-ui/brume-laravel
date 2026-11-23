<?php

namespace Brume\Form\Fields;

use Brume\Html\HtmlSanitizer;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Stringable;

/**
 * An HTML document edited with the rich text editor. Every value is sanitised on the server:
 * fill() cleans before any writer (including saveUsing) sees it, and normalizeValue() cleans
 * whatever is read back (stored row, JSON row, relation row, value() reader, default) before it
 * reaches the browser. Validation runs on the submitted HTML; an empty document fails required.
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
        $rules = parent::serverRules($record, $model, $includeUnique);
        if (in_array('required', $rules, true)) {
            $rules[] = new RichTextRequired;
        }

        return $rules;
    }

    /** The single write sink: root, JSON rows, relation rows and saveUsing all receive clean HTML. */
    public function fill(Model $record, mixed $value): void
    {
        parent::fill($record, $this->clean($value));
    }

    protected function normalizeValue(mixed $value): mixed
    {
        return $this->clean(parent::normalizeValue($value));
    }

    private function clean(mixed $value): ?string
    {
        if ($value instanceof Stringable || is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) ? app(HtmlSanitizer::class)->clean($value) : null;
    }
}
