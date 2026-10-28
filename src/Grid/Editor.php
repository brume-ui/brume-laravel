<?php

namespace Brume\Grid;

use Brume\Grid\Filters\FilterInput;
use InvalidArgumentException;

/**
 * The inline editor of an editable() column (an InlineEditor). switch and switchGroup have their
 * own Column methods.
 */
final class Editor
{
    /**
     * @param  array<string, mixed>  $schema
     */
    private function __construct(private array $schema) {}

    public static function text(?int $maxLength = null): self
    {
        if ($maxLength !== null && $maxLength < 1) {
            throw new InvalidArgumentException('Editor::text() 的最大長度至少要是 1。');
        }

        return new self(['type' => 'text'] + ($maxLength !== null ? ['maxLength' => $maxLength] : []));
    }

    public static function textarea(): self
    {
        return new self(['type' => 'textarea']);
    }

    public static function number(?float $min = null, ?float $max = null, ?float $step = null): self
    {
        return new self(['type' => 'number', ...array_filter(
            ['min' => $min, 'max' => $max, 'step' => $step],
            fn (?float $value) => $value !== null,
        )]);
    }

    /**
     * @param  array<int|string, string>  $options  value => label; values are sent as strings
     */
    public static function select(array $options): self
    {
        return self::choice('select', $options);
    }

    /**
     * @param  array<int|string, string>  $options  value => label; values are sent as strings
     */
    public static function radio(array $options): self
    {
        return self::choice('radio', $options);
    }

    /**
     * Several values at once; the cell value is a list of option values.
     *
     * @param  array<int|string, string>  $options  value => label; values are sent as strings
     */
    public static function checkbox(array $options): self
    {
        return self::choice('checkbox', $options);
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $schema
     */
    public static function fromSchema(array $schema): self
    {
        return new self($schema);
    }

    public function type(): string
    {
        return $this->schema['type'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return $this->schema;
    }

    /**
     * @param  array<int|string, string>  $options
     */
    private static function choice(string $type, array $options): self
    {
        if ($options === []) {
            throw new InvalidArgumentException("{$type} 編輯器需要選項，格式是 value => 標籤，例如 ['paid' => '已付款']。");
        }

        return new self(['type' => $type, 'options' => FilterInput::options($options)]);
    }
}
