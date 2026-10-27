<?php

namespace Brume\Grid;

use BackedEnum;
use Brume\Support\ComponentRef;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Str;
use Stringable;

class Column
{
    protected bool $numeric = false;

    protected ?Closure $display = null;

    /** @var array<string, mixed>|null */
    protected ?array $component = null;

    public function __construct(
        protected string $key,
        protected string $label,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public static function defaultLabel(string $key): string
    {
        return Str::headline($key);
    }

    public function numeric(): static
    {
        $this->numeric = true;

        return $this;
    }

    /**
     * Transform the value on the server: fn ($value, $row) => scalar|null.
     */
    public function display(Closure $fn): static
    {
        $this->display = $fn;

        return $this;
    }

    /**
     * Render the cell with a frontend component; the raw value stays in the row.
     *
     * @param  array<string, mixed>  $props
     */
    public function component(string $name, array $props = []): static
    {
        $this->component = ComponentRef::make($name, $props);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
        ]
            + ($this->numeric ? ['numeric' => true] : [])
            + ($this->component !== null ? ['display' => $this->component] : []);
    }

    /**
     * The cell value for a model: data_get by the (dotted) key, then the display closure, then made JSON-safe.
     */
    public function value(mixed $row): mixed
    {
        $value = data_get($row, $this->key);

        if ($this->display !== null) {
            $value = ($this->display)($value, $row);
        }

        return static::normalize($value);
    }

    protected static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof Stringable => (string) $value,
            default => $value,
        };
    }
}
