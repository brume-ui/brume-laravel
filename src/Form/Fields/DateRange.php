<?php

namespace Brume\Form\Fields;

use Illuminate\Database\Eloquent\Model;

class DateRange extends Date
{
    public function __construct(string $name, string $label, protected string $startColumn, protected string $endColumn)
    {
        parent::__construct($name, $label);
        self::assertPath($startColumn);
        self::assertPath($endColumn);
    }

    public function fill(Model $record, mixed $value): void
    {
        if ($this->writer !== null) {
            parent::fill($record, $value);

            return;
        }
        if ($value === null) {
            $value = ['start' => null, 'end' => null];
        }
        foreach (['start' => $this->startColumn, 'end' => $this->endColumn] as $bound => $column) {
            if (is_array($value)) {
                $item = $value[$bound] ?? null;
                if ($this->type() === 'datetimeRange' && is_string($item)) {
                    $item = str_replace('T', ' ', $item).':00';
                }
                (new Field($column))->fill($record, $item);
            }
        }
    }

    protected function extraAttributes(mixed $stored): array
    {
        return [$this->startColumn => $stored['start'] ?? null, $this->endColumn => $stored['end'] ?? null];
    }

    protected function extraStoredValue(Model $record): mixed
    {
        return ['start' => data_get($record, $this->startColumn), 'end' => data_get($record, $this->endColumn)];
    }

    public function type(): string
    {
        return 'dateRange';
    }

    public function startColumn(): string
    {
        return $this->startColumn;
    }

    public function endColumn(): string
    {
        return $this->endColumn;
    }

    public function mappedAttributes(): array
    {
        return ['start' => $this->startColumn, 'end' => $this->endColumn];
    }

    public function valueFromJson(array $stored): mixed
    {
        return ['start' => $this->normalizeValue(data_get($stored, $this->startColumn)), 'end' => $this->normalizeValue(data_get($stored, $this->endColumn))];
    }

    public function valueFor(?Model $record): mixed
    {
        $value = $record === null ? $this->defaultValue : ($this->reader !== null ? ($this->reader)($record) : ['start' => $this->readAttribute($record, $this->startColumn), 'end' => $this->readAttribute($record, $this->endColumn)]);

        return ['start' => $this->normalizeValue($value['start'] ?? null), 'end' => $this->normalizeValue($value['end'] ?? null)];
    }
}
