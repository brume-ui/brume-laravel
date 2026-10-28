<?php

namespace Brume\Grid\Filters;

use Brume\Grid\Options\RemoteOptions;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The control a filter is edited with (a FilterInput in the schema), and the value shape it
 * reads from the URL. A value of the wrong shape, or not among fixed options, is ignored, never
 * an error.
 */
final class FilterInput
{
    /**
     * @param  array<string, mixed>  $props
     */
    protected function __construct(
        protected string $type,
        protected array $props = [],
        protected ?RemoteOptions $remote = null,
    ) {}

    public static function text(?string $placeholder = null, bool $numeric = false): self
    {
        return new self('text', ['placeholder' => $placeholder, 'numeric' => $numeric]);
    }

    /**
     * @param  array<int|string, string>  $options  value => label
     */
    public static function choice(string $type, array $options): self
    {
        return new self($type, ['options' => self::options($options)]);
    }

    public static function date(bool $withTime = false): self
    {
        return new self('date', ['withTime' => $withTime]);
    }

    /**
     * @param  'text'|'number'|'date'|'datetime'  $of
     */
    public static function range(string $of = 'text'): self
    {
        return new self('range', ['of' => $of]);
    }

    /**
     * @param  'text'|'number'  $of
     * @param  list<string>  $operators
     */
    public static function group(array $operators, string $of = 'text'): self
    {
        return new self('group', ['of' => $of, 'operators' => $operators]);
    }

    public static function remote(RemoteOptions $options, bool $multiple): self
    {
        return new self($multiple ? 'multipleSelect' : 'select', [], $options);
    }

    public function type(): string
    {
        return $this->type;
    }

    /**
     * Whether the value is a list of strings.
     */
    public function isList(): bool
    {
        return in_array($this->type, ['multipleSelect', 'checkbox'], true);
    }

    public function remoteOptions(): ?RemoteOptions
    {
        return $this->remote;
    }

    /**
     * What the value holds: a range's or group's `of`, 'date' / 'datetime' for a date input,
     * 'number' for a numeric text input, else null.
     *
     * @return 'text'|'number'|'date'|'datetime'|null
     */
    public function of(): ?string
    {
        return match ($this->type) {
            'range', 'group' => $this->props['of'],
            'date' => $this->props['withTime'] ? 'datetime' : 'date',
            'text' => $this->props['numeric'] ? 'number' : null,
            default => null,
        };
    }

    /**
     * The same input holding numbers, where that applies (text, range, group); null otherwise.
     */
    public function asNumber(): ?self
    {
        return match ($this->type) {
            'text' => new self('text', ['numeric' => true] + $this->props),
            'range', 'group' => new self($this->type, ['of' => 'number'] + $this->props),
            default => null,
        };
    }

    /**
     * Fails when a choice input has nothing to choose from.
     */
    public function validate(string $label): void
    {
        if (in_array($this->type, ['select', 'multipleSelect', 'radio', 'checkbox'], true)
            && $this->remote === null && ($this->props['options'] ?? []) === []) {
            throw new InvalidArgumentException("篩選「{$label}」需要選項，請用 ->select([...])、->multipleSelect([...])、->checkbox([...]) 或 ->remoteSelect(...) 提供，例如 ->multipleSelect(['paid' => '已付款'])。");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return match ($this->type) {
            'text' => ['type' => 'text']
                + ($this->props['numeric'] ? ['inputMode' => 'numeric'] : [])
                + ($this->props['placeholder'] !== null ? ['placeholder' => $this->props['placeholder']] : []),
            'select', 'multipleSelect' => ['type' => $this->type]
                + ($this->remote !== null ? ['remote' => true] : ['options' => $this->props['options']]),
            'radio', 'checkbox' => ['type' => $this->type, 'options' => $this->props['options']],
            'date' => ['type' => 'date', 'withTime' => $this->props['withTime']],
            'range' => ['type' => 'range', 'of' => $this->props['of']],
            'group' => [
                'type' => 'group',
                'of' => $this->props['of'],
                'operators' => array_map(
                    fn (string $op) => ['value' => $op, 'label' => __('brume::grid.operators.'.$op)],
                    $this->props['operators'],
                ),
            ],
        };
    }

    /**
     * The URL value in this input's shape, or null when it is missing, empty or malformed.
     */
    public function parse(mixed $raw): mixed
    {
        if ($this->isList()) {
            $values = is_string($raw) ? [$raw] : $raw;

            if (! is_array($values) || ! array_is_list($values)) {
                return null;
            }

            $values = array_values(array_unique(array_filter($values, fn ($v) => is_string($v) && $this->allows($v))));

            return $values === [] ? null : $values;
        }

        return match ($this->type) {
            'range' => $this->parseRange($raw),
            'group' => $this->parseGroup($raw),
            'select', 'radio' => is_string($raw) && $this->allows($raw) ? $raw : null,
            default => is_string($raw) ? $this->scalar($raw, $this->of()) : null,
        };
    }

    /**
     * A choice value: one of the options, or any non-empty value for remote options.
     */
    protected function allows(string $value): bool
    {
        return $value !== '' && ($this->remote !== null || in_array($value, array_column($this->props['options'], 'value'), true));
    }

    /**
     * How an applied value reads on its chip. Remote labels come from $labels (value => label).
     *
     * @param  array<string, string>  $labels
     */
    public function display(mixed $value, array $labels = []): string
    {
        if ($this->type === 'range') {
            $start = isset($value['start']) ? $this->readable($value['start']) : null;
            $end = isset($value['end']) ? $this->readable($value['end']) : null;

            return match (true) {
                $start !== null && $end !== null => "{$start} – {$end}",
                $start !== null => "≥ {$start}",
                default => "≤ {$end}",
            };
        }

        if ($this->type === 'group') {
            return __('brume::grid.operators.'.$value['op']).' '.$value['value'];
        }

        if (in_array($this->type, ['select', 'multipleSelect', 'radio', 'checkbox'], true)) {
            $labels += array_column($this->props['options'] ?? [], 'label', 'value');

            return implode(__('brume::grid.separator'), array_map(
                fn (string $v) => $labels[$v] ?? $v,
                (array) $value,
            ));
        }

        return $this->readable($value);
    }

    /**
     * value => label pairs as Options.
     *
     * @param  array<int|string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    public static function options(array $options): array
    {
        $normalized = [];

        foreach ($options as $value => $label) {
            $normalized[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $normalized;
    }

    protected function parseRange(mixed $raw): ?array
    {
        if (! is_array($raw) || array_is_list($raw)) {
            return null;
        }

        $range = [];

        foreach (['start', 'end'] as $end) {
            $value = isset($raw[$end]) && is_string($raw[$end]) ? $this->scalar($raw[$end], $this->props['of']) : null;

            if ($value !== null) {
                $range[$end] = $value;
            }
        }

        return $range === [] ? null : $range;
    }

    /**
     * @return array{op: string, value: string}|null
     */
    protected function parseGroup(mixed $raw): ?array
    {
        if (! is_array($raw) || ! is_string($raw['op'] ?? null) || ! is_string($raw['value'] ?? null)
            || ! in_array($raw['op'], $this->props['operators'], true)) {
            return null;
        }

        $value = $this->scalar($raw['value'], $this->props['of']);

        return $value === null ? null : ['op' => $raw['op'], 'value' => $value];
    }

    /**
     * A trimmed string that suits $of, or null.
     */
    protected function scalar(string $value, ?string $of): ?string
    {
        $value = Str::trim($value);

        $valid = match ($of) {
            'number' => is_numeric($value),
            'date' => self::isDate($value),
            'datetime' => (bool) preg_match('/^(\d{4}-\d{2}-\d{2})[T ]([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value, $m) && self::isDate($m[1]),
            default => $value !== '',
        };

        return $valid ? $value : null;
    }

    protected static function isDate(string $value): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * A datetime-local value reads with a space instead of the T.
     */
    protected function readable(string $value): string
    {
        return $this->of() === 'datetime' ? str_replace('T', ' ', $value) : $value;
    }
}
