<?php

namespace Brume\Form\Fields;

use Brume\Form\JsonValues;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CustomField extends Field
{
    public function __construct(string $name, string $label, string $component, array $props = [])
    {
        parent::__construct($name, $label);
        if (trim($component) === '') {
            throw new InvalidArgumentException('Component name must not be empty.');
        }
        $this->schema['component'] = ['type' => 'component', 'name' => $component] + ($props !== [] ? ['props' => $props] : []);
    }

    protected array $childrenRules = [];

    public function childRules(array $rules): static
    {
        foreach ($rules as $path => $rule) {
            self::assertValidationPath((string) $path);
        }
        $this->childrenRules = $rules;

        return $this;
    }

    public function childValidationRules(): array
    {
        return $this->childrenRules;
    }

    public function valueFor(?Model $record): mixed
    {
        $value = parent::valueFor($record);

        return $record !== null && $this->reader === null && $this->mappedAttributes() === []
            ? JsonValues::storedValue($record, $this->attributeName(), $value)
            : $value;
    }

    public function type(): string
    {
        return 'custom';
    }

    public function displayOnly(bool $on = true): static
    {
        $this->schema['displayOnly'] = $on;

        return $this;
    }

    public function isEditable(?Model $record): bool
    {
        return ! ($this->schema['displayOnly'] ?? false) && parent::isEditable($record);
    }
}
