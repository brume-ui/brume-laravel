<?php

namespace Brume\Form\Fields;

use BackedEnum;
use Brume\Form\ClientRules;
use Brume\Form\Conditions;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use InvalidArgumentException;

/** A declared value and its public field definition. Model paths stay on the server. */
class Field
{
    protected array $schema = [];

    public function showWhen(Conditions $condition): static
    {
        $this->schema['showWhen'] = $condition->jsonSerialize();

        return $this;
    }

    public function enableWhen(Conditions $condition): static
    {
        $this->schema['enableWhen'] = $condition->jsonSerialize();

        return $this;
    }

    public function excludeSelectedIn(array $paths): static
    {
        if (! in_array($this->type(), ['select', 'multipleSelect', 'tags', 'radio', 'checkbox', 'relation'], true)) {
            throw new InvalidArgumentException('Exclusions require a choice field.');
        }
        $this->schema['excludeSelectedIn'] = array_map(fn ($path) => Conditions::path($path)->jsonSerialize(), $paths);

        return $this;
    }

    protected array|string|Closure $validationRules = [];

    protected array $validationMessages = [];

    protected ?array $uniqueConstraint = null;

    protected ?string $patternSource = null;

    public function rules(array|string|Closure $rules): static
    {
        $this->validationRules = $rules;

        return $this;
    }

    public function messages(array $messages): static
    {
        $this->validationMessages = $messages;

        return $this;
    }

    public function unique(?string $table = null, ?string $column = null): static
    {
        $this->uniqueConstraint = [$table, $column];

        return $this;
    }

    public function pattern(string $regex, ?string $message = null): static
    {
        $this->patternSource = $regex;
        if ($message !== null) {
            $this->validationMessages['regex'] = $message;
        }

        return $this;
    }

    public function minLength(int $length): static
    {
        if ($length < 0) {
            throw new InvalidArgumentException('Min length must be non-negative.');
        }
        $this->schema['minLength'] = $length;

        return $this;
    }

    public function maxLength(int $length): static
    {
        if ($length < 0) {
            throw new InvalidArgumentException('Max length must be non-negative.');
        }
        $this->schema['maxLength'] = $length;

        return $this;
    }

    public function settings(): array
    {
        return $this->schema;
    }

    public function validationLabel(): string
    {
        return $this->label;
    }

    public function validationMessages(): array
    {
        return $this->validationMessages;
    }

    public function validationPattern(): ?string
    {
        return $this->patternSource ?? ($this->type() === 'mobile' ? '^09\\d{8}$' : null);
    }

    public function isEditable(?Model $record): bool
    {
        return $this->type() !== 'display' && ! $this->isReadOnly($record) && ! ($this->schema['disabled'] ?? false);
    }

    public function impliedRules(): array
    {
        $rules = match ($this->type()) {
            'email' => ['email'], 'url' => ['url:http,https'], 'number' => ['integer'],
            'decimal', 'currency' => ['string', 'numeric'],
            'select', 'radio' => ['string', $this->choiceRule()],
            'multipleSelect', 'checkbox', 'tags' => ['array', 'list'],
            'upload' => ($this->schema['multiple'] ?? false) ? ['array', 'list'] : [],
            'date' => ['date_format:Y-m-d'], 'datetime' => ['date_format:Y-m-d\\TH:i'],
            'dateRange', 'datetimeRange' => ['array:start,end'], 'switch' => ['boolean'], default => [],
        };
        if ($this->attributeMap !== []) {
            $rules[] = 'array:'.implode(',', array_keys($this->attributeMap));
        }
        if ($this->schema['required'] ?? false) {
            $rules[] = 'required';
        }
        foreach (['minLength' => 'min', 'maxLength' => 'max', 'min' => 'min', 'max' => 'max', 'maxFiles' => 'max'] as $key => $rule) {
            if (in_array($this->type(), ['tags', 'checkbox', 'multipleSelect'], true) && in_array($key, ['minLength', 'maxLength'], true)) {
                continue;
            }
            if (isset($this->schema[$key])) {
                $rules[] = $rule.':'.$this->schema[$key];
            }
        }
        if ($this->validationPattern() !== null) {
            $rules[] = 'regex:~'.str_replace('~', '\\~', $this->validationPattern()).'~u';
        }

        return $rules;
    }

    public function choiceRule(): In
    {
        $choices = array_column($this->schema['options'] ?? [], 'value');

        return Rule::in($choices);
    }

    public function serverRules(?Model $record = null, ?Model $model = null, bool $includeUnique = true): array
    {
        $extra = $this->validationRules instanceof Closure ? ($this->validationRules)($record) : $this->validationRules;
        $rules = array_merge(['nullable'], $this->impliedRules(), is_string($extra) ? explode('|', $extra) : $extra);
        if ($includeUnique && $this->uniqueConstraint !== null) {
            [$table, $column] = $this->uniqueConstraint;
            $model ??= $record;
            if ($table === null && $model === null) {
                throw new InvalidArgumentException('unique() needs a form model or explicit table.');
            }
            $unique = Rule::unique($table ?? (($model->getConnectionName() ? $model->getConnectionName().'.' : '').$model->getTable()), $column ?? $this->attributeName());
            if ($record?->exists) {
                $unique->ignore($record);
            }
            $rules[] = $unique;
        }

        return $rules;
    }

    protected mixed $defaultValue = null;

    protected ?Closure $reader = null;

    protected ?Closure $writer = null;

    protected bool|Closure $readonly = false;

    protected ?string $attributeName = null;

    protected array $attributeMap = [];

    protected string $prefix = '';

    public function __construct(protected string $name, protected ?string $label = null)
    {
        self::assertPath($name);
        $this->label ??= Str::headline($name);
    }

    public static function assertPath(string $path): void
    {
        foreach (explode('.', $path) as $segment) {
            if (! preg_match('/^(?:[A-Za-z_$][A-Za-z0-9_$-]*|[0-9]+)$/D', $segment) || in_array($segment, ['__proto__', 'prototype', 'constructor', '*', '_brume'], true)) {
                throw new InvalidArgumentException("Unsafe form path: {$path}");
            }
        }
    }

    public static function assertValidationPath(string $path): void
    {
        self::assertPath(implode('.', array_map(fn ($segment) => $segment === '*' ? '0' : $segment, explode('.', $path))));
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

    public function attributes(array $attributes): static
    {
        if ($attributes === []) {
            throw new InvalidArgumentException('Attribute mapping cannot be empty.');
        }
        foreach ($attributes as $key => $attribute) {
            self::assertPath((string) $key);
            self::assertPath($attribute);
            if (str_contains((string) $key, '.') || $key === '_brume') {
                throw new InvalidArgumentException('Invalid mapped object key.');
            }
        }
        foreach ($attributes as $key => $attribute) {
            foreach ($attributes as $otherKey => $other) {
                if ($key !== $otherKey && (str_starts_with($attribute, $other.'.') || str_starts_with($other, $attribute.'.'))) {
                    throw new InvalidArgumentException('Overlapping mapped attributes.');
                }
            }
        }
        if (count(array_unique($attributes)) !== count($attributes)) {
            throw new InvalidArgumentException('Duplicate mapped attribute.');
        }
        $this->attributeMap = $attributes;

        return $this;
    }

    public function mappedAttributes(): array
    {
        return $this->attributeMap;
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

    public function fill(Model $record, mixed $value): void
    {
        if ($this->writer !== null) {
            ($this->writer)($record, $value);

            return;
        }
        if ($this->attributeMap !== []) {
            $value = $value instanceof \stdClass ? get_object_vars($value) : $value;
            foreach ($this->attributeMap as $key => $attribute) {
                if ($value === null || array_key_exists($key, $value)) {
                    (new Field($attribute))->fill($record, $value[$key] ?? null);
                }
            }

            return;
        }
        $path = $this->attributeName();
        if (str_contains($path, '.')) {
            [$root, $rest] = explode('.', $path, 2);
            if (! $record->hasCast($root, ['array', 'json'])) {
                throw new \LogicException('Embedded attribute requires an array or json cast: '.$root);
            }
            $object = $record->getAttribute($root) ?? [];
            data_set($object, $rest, $value);
            $record->forceFill([$root => $object]);

            return;
        }
        if ($this->type() === 'datetime' && is_string($value)) {
            $value = str_replace('T', ' ', $value).':00';
        }
        $record->forceFill([$path => $value]);
    }

    /** Convert one declared extra value using the same writer as a root field. */
    public function storeExtraValue(mixed $value, mixed $stored): mixed
    {
        $record = $this->extraRecord($stored);
        $this->fill($record, $value);

        return $this->extraStoredValue($record);
    }

    public function extraValueFor(mixed $stored): mixed
    {
        return $this->valueFor($this->extraRecord($stored));
    }

    protected function extraAttributes(mixed $stored): array
    {
        return [$this->attributeName() => $stored];
    }

    protected function extraStoredValue(Model $record): mixed
    {
        return data_get($record, $this->attributeName());
    }

    /** Storage context only: editability always uses the real nullable root record. */
    protected function extraRecord(mixed $stored): Model
    {
        $record = new class extends Model
        {
            public function getAttribute($key)
            {
                return (string) $key === '0' ? $this->getAttributeValue($key) : parent::getAttribute($key);
            }
        };
        foreach ($this->extraAttributes($stored) as $column => $value) {
            if (str_contains((string) $column, '.')) {
                $record->mergeCasts([explode('.', $column, 2)[0] => 'array']);
            }
            (new Field((string) $column))->fill($record, $value);
        }

        return $record;
    }

    /** Internal recursive serializer preserves global root context for JSON templates. */
    public function schemaFor(?Model $record, ?Model $root): array
    {
        return $this->toSchema($record);
    }

    public function toSchema(?Model $record = null): array
    {
        $rules = $this->isEditable($record) ? ClientRules::for($this, $this->path(), $record) : [];

        return ($rules !== [] ? ['rules' => $rules] : []) + ['type' => $this->type(), 'name' => $this->name, 'label' => $this->label] + $this->schema + ($this->isReadOnly($record) ? ['readOnly' => true] : []);
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

    public function hasHiddenAttributes(Model $record): bool
    {
        foreach ($this->mappedAttributes() ?: [$this->attributeName()] as $attribute) {
            if ($this->attributeIsHidden($record, $attribute)) {
                return true;
            }
        }

        return false;
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

    public function valueFromJson(array $stored): mixed
    {
        if ($this->attributeMap !== []) {
            return array_map(fn ($attribute) => data_get($stored, $attribute), $this->attributeMap);
        }

        return $this->normalizeValue(data_get($stored, $this->attributeName(), $this->defaultValue));
    }

    public function valueFor(?Model $record): mixed
    {
        if ($this->attributeMap !== [] && $this->reader === null) {
            return $record === null ? $this->defaultValue : array_map(fn ($attribute) => $this->readAttribute($record, $attribute), $this->attributeMap);
        }
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
