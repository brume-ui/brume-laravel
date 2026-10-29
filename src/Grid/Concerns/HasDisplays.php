<?php

namespace Brume\Grid\Concerns;

use Brume\Grid\Content;
use Brume\Support\ComponentRef;
use Closure;
use InvalidArgumentException;
use LogicException;
use Traversable;

/**
 * How a column shows its cell (its Display). A column has at most one: a built-in display or a
 * component(). display() still transforms the value first.
 */
trait HasDisplays
{
    public const TONES = ['neutral', 'accent', 'success', 'warning', 'danger', 'info'];

    /** @var array<string, mixed>|null */
    protected ?array $displaySchema = null;

    /** fn (Model $row): ?string, for link() and button(). */
    protected ?Closure $href = null;

    /** fn (Model $row): ?Content, for expand() and modal(). */
    protected ?Closure $content = null;

    /** @var array<string, string> key => label, for table() */
    protected array $tableColumns = [];

    /**
     * Render the cell with a frontend component; the raw value stays in the row.
     *
     * @param  array<string, mixed>  $props
     */
    public function component(string $name, array $props = []): static
    {
        return $this->useDisplay(ComponentRef::make($name, $props), 'component()');
    }

    /**
     * Show the value (or each value of a list) as a label. $options maps a value to its label, or
     * to [label, tone]; a plain label takes $tone, and a value outside $options shows as it is in
     * neutral. Without $options every value shows in $tone.
     *
     * @param  array<int|string, string|array{0: string, 1: string}>  $options
     */
    public function label(array $options = [], string $tone = 'neutral'): static
    {
        static::assertTone($tone);

        if ($options === []) {
            return $this->useDisplay(['type' => 'label', 'tone' => $tone], 'label()');
        }

        $labels = [];

        foreach ($options as $value => $option) {
            if (is_array($option)) {
                if (! array_is_list($option) || count($option) !== 2) {
                    throw new InvalidArgumentException("label() 的選項「{$value}」要是標籤字串或 [標籤, 色調]，例如 ['已取消', 'danger']。");
                }

                static::assertTone($option[1]);
                $labels[(string) $value] = ['label' => (string) $option[0], 'tone' => $option[1]];
            } else {
                $labels[(string) $value] = ['label' => (string) $option, 'tone' => $tone];
            }
        }

        // Values 0, 1, ... make a PHP list, which would encode as a JSON array instead of an object.
        return $this->useDisplay(['type' => 'label', 'options' => array_is_list($labels) ? (object) $labels : $labels], 'label()');
    }

    /**
     * A count-style badge.
     */
    public function badge(string $tone = 'neutral'): static
    {
        static::assertTone($tone);

        return $this->useDisplay(['type' => 'badge', 'tone' => $tone], 'badge()');
    }

    /**
     * The value as a link to fn (Model $row): ?string; a row whose closure returns null shows plain text.
     */
    public function link(Closure $href, bool $external = false): static
    {
        $this->href = $href;

        return $this->useDisplay(['type' => 'link'] + ($external ? ['external' => true] : []), 'link()');
    }

    /**
     * The value as a button-styled link to fn (Model $row): ?string.
     */
    public function button(Closure $href): static
    {
        $this->href = $href;

        return $this->useDisplay(['type' => 'link', 'style' => 'button'], 'button()');
    }

    /**
     * An image, or several: the value is a URL or a list of URLs.
     */
    public function image(?int $width = null, ?int $height = null): static
    {
        static::assertBound($width, 'image() 的寬度');
        static::assertBound($height, 'image() 的高度');

        return $this->useDisplay(['type' => 'image']
            + ($width !== null ? ['width' => $width] : [])
            + ($height !== null ? ['height' => $height] : []), 'image()');
    }

    /**
     * The first $length characters; the whole text opens from 「…」.
     */
    public function limit(int $length): static
    {
        if ($length < 1) {
            throw new InvalidArgumentException('limit() 的長度至少要是 1。');
        }

        return $this->useDisplay(['type' => 'limit', 'length' => $length], 'limit()');
    }

    /**
     * A progress bar of the value out of $max.
     */
    public function progressBar(int $max = 100): static
    {
        if ($max < 1) {
            throw new InvalidArgumentException('progressBar() 的最大值至少要是 1。');
        }

        return $this->useDisplay(['type' => 'progress', 'max' => $max], 'progressBar()');
    }

    /**
     * A button that shows the value as a QR code.
     */
    public function qrcode(?int $size = null): static
    {
        static::assertBound($size, 'qrcode() 的尺寸');

        return $this->useDisplay(['type' => 'qrcode'] + ($size !== null ? ['size' => $size] : []), 'qrcode()');
    }

    /**
     * The value with a copy button.
     */
    public function copyable(): static
    {
        return $this->useDisplay(['type' => 'copyable'], 'copyable()');
    }

    /**
     * Download links: the value is a URL, ['url' => ..., 'name' => ...], or a list of them.
     */
    public function downloadable(): static
    {
        return $this->useDisplay(['type' => 'downloadable'], 'downloadable()');
    }

    /**
     * A toggle that opens a full-width row under this one with fn (Model $row): ?Content. The
     * content is built for every row of the page; a row whose closure returns null shows the value.
     */
    public function expand(Closure $content, ?string $label = null): static
    {
        $this->content = $content;

        return $this->useDisplay(['type' => 'expand'] + ($label !== null ? ['label' => $label] : []), 'expand()');
    }

    /**
     * A button that opens fn (Model $row): ?Content in a dialog. The content is built for every
     * row of the page; a row whose closure returns null shows the value.
     */
    public function modal(Closure $content, ?string $title = null, ?string $label = null): static
    {
        $this->content = $content;

        return $this->useDisplay(['type' => 'modal']
            + ($label !== null ? ['label' => $label] : [])
            + ($title !== null ? ['title' => $title] : []), 'modal()');
    }

    /**
     * A small table: the value is a list of rows (arrays, objects or models), of which only the
     * declared columns are sent.
     *
     * @param  array<string, string>  $columns  key => label
     */
    public function table(array $columns): static
    {
        $schema = ['type' => 'table', 'columns' => Content::tableColumns($columns)];
        $this->tableColumns = $columns;

        return $this->useDisplay($schema, 'table()');
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function useDisplay(array $schema, string $method): static
    {
        if (in_array($schema['type'], ['label', 'image', 'downloadable', 'table'], true)) {
            $this->assertKeepsRowId($method);
        }

        if ($this->displaySchema !== null) {
            $current = $this->displaySchema['type'] === 'component' ? 'component()' : "「{$this->displaySchema['type']}」顯示器";

            throw new LogicException("欄位「{$this->key}」已經設定了 {$current}，一個欄位只能有一種顯示方式，不能再用 {$method}。");
        }

        $this->displaySchema = $schema;

        return $this;
    }

    /**
     * Whether the display shows a list of values, so display() may return an array.
     */
    protected function displaysList(): bool
    {
        return in_array($this->displaySchema['type'] ?? null, ['label', 'image', 'downloadable', 'table'], true);
    }

    /**
     * The value of a label(), image(), downloadable() or table() cell, checked against what the
     * display takes, so a model or a nested object never reaches the page whole:
     * - label: a scalar or a list of scalars;
     * - image: a URL or a list of URLs;
     * - downloadable: a URL, ['url' => ..., 'name' => ...], or a list of either;
     * - table: rows (arrays, objects or models) cut down to the declared columns.
     */
    protected function listDisplayValue(mixed $value): mixed
    {
        $type = $this->displaySchema['type'];

        if ($type === 'table') {
            if ($value === null) {
                return null;
            }

            if (! is_iterable($value)) {
                throw new InvalidArgumentException("欄位「{$this->key}」是 table() 顯示器，值必須是一組資料列（陣列或 Collection），目前是 ".get_debug_type($value).'。');
            }

            return Content::tableRows($this->tableColumns, $value);
        }

        $value = static::normalize($value);

        if ($value === null || is_scalar($value) || ($type === 'downloadable' && static::isFileItem($value))) {
            return static::safeUrlItem($type, $value);
        }

        if ((is_array($value) && array_is_list($value)) || $value instanceof Traversable) {
            $items = [];

            foreach ($value as $item) {
                $item = static::normalize($item);

                if (! ($item === null || is_scalar($item) || ($type === 'downloadable' && static::isFileItem($item)))) {
                    throw new InvalidArgumentException("欄位「{$this->key}」的 {$type}() 顯示器清單裡有 ".get_debug_type($item).'，每一項都必須是'.static::listItemHint($type).'。');
                }

                // An item with an unsafe URL is left out of the list.
                if ($item === null || ($item = static::safeUrlItem($type, $item)) !== null) {
                    $items[] = $item;
                }
            }

            return $items;
        }

        throw new InvalidArgumentException("欄位「{$this->key}」的 {$type}() 顯示器收到 ".get_debug_type($value).'，值必須是'.static::listItemHint($type).'，或它們的清單。');
    }

    /**
     * An image or downloadable item, or null when its URL has a scheme safeHref() refuses.
     */
    protected static function safeUrlItem(string $type, mixed $item): mixed
    {
        if (! in_array($type, ['image', 'downloadable'], true)) {
            return $item;
        }

        if (is_string($item)) {
            return static::safeHref($item);
        }

        if (is_array($item)) {
            return static::safeHref($item['url']) !== null ? $item : null;
        }

        return $item;
    }

    /**
     * ['url' => string, 'name' => ?string] and nothing else.
     */
    protected static function isFileItem(mixed $value): bool
    {
        return is_array($value)
            && isset($value['url']) && is_string($value['url'])
            && (! array_key_exists('name', $value) || is_string($value['name']))
            && array_diff(array_keys($value), ['url', 'name']) === [];
    }

    protected static function listItemHint(string $type): string
    {
        return match ($type) {
            'image' => '圖片網址（字串）',
            'downloadable' => '網址字串或 [\'url\' => ..., \'name\' => ...]',
            default => '純量（字串或數字）',
        };
    }

    /**
     * row._meta entries of the display: href (link, button) and content (expand, modal).
     *
     * @return array{href?: string, content?: array<string, mixed>}
     */
    protected function displayMeta(mixed $row): array
    {
        $meta = [];

        if ($this->href !== null && ($href = static::safeHref(($this->href)($row))) !== null) {
            $meta['href'] = $href;
        }

        if ($this->content !== null && ($content = ($this->content)($row)) !== null) {
            if (! $content instanceof Content) {
                throw new InvalidArgumentException("欄位「{$this->key}」的內容 closure 必須回傳 Content 或 null，例如 Content::text(...)，目前回傳 ".get_debug_type($content).'。');
            }

            $meta['content'] = $content->toArray();
        }

        return $meta;
    }

    /**
     * The href, or null (the cell shows plain text) for a scheme other than http, https, mailto and
     * tel, such as javascript: or data:. Relative URLs pass. Browsers ignore whitespace and control
     * characters around and inside a scheme, so they are dropped before it is read.
     */
    protected static function safeHref(mixed $href): ?string
    {
        if ($href === null) {
            return null;
        }

        $href = (string) $href;
        $compact = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $href));

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $compact, $match) === 1
            && ! in_array($match[1], ['http', 'https', 'mailto', 'tel'], true)) {
            return null;
        }

        return $href;
    }

    protected static function assertBound(?int $value, string $what): void
    {
        if ($value !== null && $value < 1) {
            throw new InvalidArgumentException("{$what}至少要是 1。");
        }
    }

    protected static function assertTone(string $tone): void
    {
        if (! in_array($tone, self::TONES, true)) {
            throw new InvalidArgumentException("色調「{$tone}」不存在，可用的有 ".implode('、', self::TONES).'。');
        }
    }
}
