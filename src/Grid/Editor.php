<?php

namespace Brume\Grid;

use Brume\Form\Fields\Currency;
use Brume\Grid\Filters\FilterInput;
use Closure;
use Illuminate\Database\Eloquent\Model;
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

    public static function date(bool $withTime = false): self
    {
        return new self(['type' => 'date', 'withTime' => $withTime]);
    }

    public static function currency(string $symbol = 'NT$', int $decimals = 0): self
    {
        if ($decimals < 0) {
            throw new InvalidArgumentException('Decimals must be non-negative.');
        }

        return new self(['type' => 'currency', 'symbol' => $symbol, 'decimals' => $decimals]);
    }

    public static function tags(array $options = []): self
    {
        return new self(['type' => 'tags'] + ($options !== [] ? ['options' => FilterInput::options($options)] : []));
    }

    public function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $options = array_column($this->schema['options'] ?? [], 'value');

        return match ($this->type()) {
            'select', 'radio' => $this->matchOption($options, $value),
            'checkbox' => array_map(fn ($item) => $this->matchOption($options, $item), $value),
            'switch' => in_array($value, [true, 1, '1'], true),
            'switchGroup' => array_map(fn ($item) => in_array($item, [true, 1, '1'], true), $value),
            'date' => ($this->schema['withTime'] ?? false) ? str_replace('T', ' ', $value).':00' : $value,
            'currency' => (new Currency('value'))->decimals($this->schema['decimals'])->default($value)->valueFor(null),
            default => $value,
        };
    }

    public function prepare(mixed $value): mixed
    {
        if (in_array($this->type(), ['text', 'textarea'], true)) {
            return $value;
        }

        return match (true) {
            is_string($value) && trim($value) === '' => null,
            is_array($value) => array_map(fn (mixed $item) => $this->prepare($item), $value),
            default => $value,
        };
    }

    /**
     * The declared option $value names: a string or int whose string form is an option value
     * exactly ("01" is not "1"; true and 1.0 are no option), else null.
     *
     * @param  list<string>  $options
     */
    protected function matchOption(array $options, mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return in_array((string) $value, $options, true) ? (string) $value : null;
    }

    /**
     * The editor's implied rules. A select, radio or checkbox value must be
     * a declared option (see matchOption()); a number must be whole when $model casts the
     * attribute to int or the editor's step is whole.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(Column $column, ?Model $model = null): array
    {
        $options = array_column($this->schema['options'] ?? [], 'value');
        $key = $column->key();
        $editor = $this->schema;
        $whole = $model?->hasCast($key, ['int', 'integer'])
            || (isset($editor['step']) && floor($editor['step']) === (float) $editor['step']);

        return match ($editor['type']) {
            'select', 'radio' => [$key => [$this->optionRule($options)]],
            'checkbox' => [$key => ['array', 'list', $this->optionsRule($options)]],
            'switch' => [$key => ['boolean']],
            'switchGroup' => [$key => ['required', 'array:'.implode(',', array_keys($column->switchKeys()))]]
                + array_fill_keys(array_map(fn (string|int $attribute) => "{$key}.{$attribute}", array_keys($column->switchKeys())), ['boolean']),
            'number' => [$key => [
                'numeric',
                ...($whole ? ['integer'] : []),
                ...(isset($editor['min']) ? ['min:'.$editor['min']] : []),
                ...(isset($editor['max']) ? ['max:'.$editor['max']] : []),
            ]],
            'date' => [$key => ['date_format:'.(($editor['withTime'] ?? false) ? 'Y-m-d\\TH:i' : 'Y-m-d')]],
            'currency' => [$key => ['regex:/^[+-]?[0-9]+(?:\.[0-9]*)?$/D']],
            'tags' => [$key => ['array', 'list'], $key.'.*' => ['string']],
            default => [$key => [
                $this->textRule(),
                ...(isset($editor['maxLength']) ? ['max:'.$editor['maxLength']] : []),
            ]],
        };
    }

    /**
     * @param  list<string>  $options
     */
    protected function optionRule(array $options): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($options) {
            if ($this->matchOption($options, $value) === null) {
                $fail(__('validation.in'));
            }
        };
    }

    /**
     * Each item of a list is a declared option (a value that is not a list is refused by 'array').
     *
     * @param  list<string>  $options
     */
    protected function optionsRule(array $options): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($options) {
            if (is_array($value) && array_filter($value, fn (mixed $item) => $this->matchOption($options, $item) === null) !== []) {
                $fail(__('validation.in'));
            }
        };
    }

    /**
     * A text or textarea takes a scalar (or null, which the column's rules may refuse), not a
     * list or an object.
     */
    protected function textRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_scalar($value) && $value !== null) {
                $fail(__('brume::grid.cell.not_text'));
            }
        };
    }

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
