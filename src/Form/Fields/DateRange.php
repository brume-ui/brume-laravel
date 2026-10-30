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

    public function valueFor(?Model $record): mixed
    {
        $value = $record === null ? $this->defaultValue : ($this->reader !== null ? ($this->reader)($record) : ['start' => $this->readAttribute($record, $this->startColumn), 'end' => $this->readAttribute($record, $this->endColumn)]);

        return ['start' => $this->normalizeValue($value['start'] ?? null), 'end' => $this->normalizeValue($value['end'] ?? null)];
    }
}
