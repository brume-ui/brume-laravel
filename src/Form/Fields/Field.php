<?php

namespace Brume\Form\Fields;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** A declared value and its public field definition. Model paths stay on the server. */
class Field
{
    protected array $schema = [];

    protected mixed $defaultValue = null;

    protected ?Closure $reader = null;

    protected ?Closure $writer = null;

    protected bool|Closure $readonly = false;

    protected ?string $attributeName = null;

    protected string $prefix = '';

    public function __construct(protected string $name, protected ?string $label = null)
    {
        self::assertPath($name);
        $this->label ??= Str::headline($name);
    }

    public static function assertPath(string $path): void
    {
        foreach (explode('.', $path) as $segment) {
            if ($segment === '' || in_array($segment, ['__proto__', 'prototype', 'constructor', '*'], true)) {
                throw new InvalidArgumentException("Unsafe form path: {$path}");
            }
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): string
    {
        return $this->prefix.$this->name;
    }

    public function attributeName(): string
    {
        return $this->attributeName ?? $this->path();
    }

    public function inContainer(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function type(): string
    {
        return 'text';
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function help(string $help): static
    {
        $this->schema['help'] = $help;

        return $this;
    }

    public function placeholder(string $placeholder): static
    {
        $this->schema['placeholder'] = $placeholder;

        return $this;
    }

    public function default(mixed $value): static
    {
        $this->defaultValue = $value;

        return $this;
    }

    public function required(bool $on = true): static
    {
        $this->schema['required'] = $on;

        return $this;
    }

    public function readOnly(bool|Closure $when = true): static
    {
        $this->readonly = $when;

        return $this;
    }

    public function isReadOnly(?Model $record): bool
    {
        return $this->readonly instanceof Closure ? (bool) ($this->readonly)($record) : $this->readonly;
    }

    public function disabled(bool $on = true): static
    {
        $this->schema['disabled'] = $on;

        return $this;
    }

    public function span(string $span): static
    {
        if (! in_array($span, ['auto', 'full'], true)) {
            throw new InvalidArgumentException('Span must be auto or full.');
        }
        $this->schema['span'] = $span;

        return $this;
    }

    public function width(int $width): static
    {
        if ($width < 1 || $width > 12) {
            throw new InvalidArgumentException('Width must be between 1 and 12.');
        }
        $this->schema['width'] = $width;

        return $this;
    }

    public function gridWidth(): int
    {
        return $this->schema['width'] ?? 12;
    }

    public function isLong(): bool
    {
        return ($this->schema['span'] ?? null) === 'full' || in_array($this->type(), ['textarea', 'tags', 'upload', 'checkbox', 'dateRange', 'datetimeRange'], true);
    }

    public function attribute(string $attribute): static
    {
        self::assertPath($attribute);
        $this->attributeName = $attribute;

        return $this;
    }

    public function value(Closure $fn): static
    {
        $this->reader = $fn;

        return $this;
    }

    public function saveUsing(Closure $fn): static
    {
        $this->writer = $fn;

        return $this;
    }

    public function toSchema(?Model $record = null): array
    {
        return ['type' => $this->type(), 'name' => $this->name, 'label' => $this->label] + $this->schema + ($this->isReadOnly($record) ? ['readOnly' => true] : []);
    }

    /** Hidden attributes are checked at every model boundary, including hidden JSON roots. */
    protected function readAttribute(Model $record, string $path): mixed
    {
        $value = $record;
        foreach (explode('.', $path) as $segment) {
            if ($value instanceof Model && in_array($segment, $value->getHidden(), true)) {
                return null;
            }
            $value = data_get($value, $segment);
        }

        return $value;
    }

    protected function attributeIsHidden(Model $record, string $path): bool
    {
        $value = $record;
        foreach (explode('.', $path) as $segment) {
            if ($value instanceof Model && in_array($segment, $value->getHidden(), true)) {
                return true;
            }
            $value = data_get($value, $segment);
        }

        return false;
    }

    public function valueFor(?Model $record): mixed
    {
        if ($record !== null && $this->reader === null && $this->attributeIsHidden($record, $this->attributeName())) {
            return null;
        }
        $value = $record === null ? $this->defaultValue : ($this->reader !== null ? ($this->reader)($record) : $this->readAttribute($record, $this->attributeName()));

        return $this->normalizeValue($value);
    }

    protected function normalizeValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            default => $value,
        };
    }
}
