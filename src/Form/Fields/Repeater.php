<?php

namespace Brume\Form\Fields;

use Brume\Form\Form;
use Brume\Form\FormSnapshot;
use Brume\Form\JsonRows;
use Brume\Form\JsonValues;
use Brume\Options\OptionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use LogicException;

class Repeater extends Field
{
    protected ?string $relationName = null;

    protected ?string $orderAttribute = null;

    protected array $schema = ['mode' => 'json', 'sortable' => true];

    public function __construct(string $name, string $label, protected Form $rowForm)
    {
        parent::__construct($name, $label);
    }

    public function __clone()
    {
        $this->rowForm = clone $this->rowForm;
    }

    public function type(): string
    {
        return 'repeater';
    }

    public function rowForm(): Form
    {
        return $this->rowForm;
    }

    public function relation(string $name): static
    {
        self::assertPath($name);
        if (str_contains($name, '.')) {
            throw new InvalidArgumentException('Declare nested relations at their own row.');
        }
        $this->relationName = $name;
        $this->schema['mode'] = 'relation';
        $this->schema['sortable'] = $this->orderAttribute !== null;

        return $this;
    }

    public function relationName(): ?string
    {
        return $this->relationName;
    }

    public function orderColumn(string $attribute): static
    {
        OptionSource::assertAttribute($attribute);
        $this->orderAttribute = $attribute;
        $this->schema['sortable'] = true;

        return $this;
    }

    public function orderAttribute(): ?string
    {
        return $this->orderAttribute;
    }

    public function distinct(string $path): static
    {
        self::assertPath($path);
        $this->schema['distinct'][] = $path;

        return $this;
    }

    public function min(int $min): static
    {
        if ($min < 0) {
            throw new InvalidArgumentException('Minimum must be nonnegative.');
        }
        $this->schema['min'] = $min;

        return $this;
    }

    public function max(int $max): static
    {
        if ($max < 0) {
            throw new InvalidArgumentException('Maximum must be nonnegative.');
        }
        $this->schema['max'] = $max;

        return $this;
    }

    public function relationship(Model $parent): HasMany
    {
        if ($this->relationName === null || ! method_exists($parent, $this->relationName)) {
            throw new InvalidArgumentException('Unknown repeater relation.');
        }
        $relation = $parent->{$this->relationName}();
        if (! $relation instanceof HasMany) {
            throw new InvalidArgumentException('Repeater relation requires HasMany.');
        }

        return $relation;
    }

    public function toSchema(?Model $record = null): array
    {
        return $this->schemaFor($record, $record);
    }

    public function schemaFor(?Model $record, ?Model $root): array
    {
        return parent::toSchema($record) + ['nodes' => $this->rowForm->nodesSchema($this->relationName !== null ? null : $root, $root)];
    }

    public function storedJsonRows(Model $record): array
    {
        return $this->storedJsonRowsForContext($record, $record);
    }

    /** Keep encoded storage authority separate from a declared reader's association context. */
    public function storedJsonRowsForContext(Model $storage, ?Model $context): array
    {
        $value = parent::valueFor($this->reader === null ? $storage : $context);

        return JsonValues::storedValue($storage, $this->attributeName(), $value) ?? [];
    }

    public function valueFor(?Model $record): mixed
    {
        return (new FormSnapshot)->value($this, $record, root: $record);
    }

    public function fill(Model $record, mixed $value): void
    {
        $this->fillWithOriginal($record, $value, []);
    }

    /** Internal graph writer: original rows are locked raw storage, never client dictionaries. */
    public function fillWithOriginal(Model $record, mixed $value, array $original, ?Model $root = null, string $template = '', array $context = []): void
    {
        if ($this->relationName !== null) {
            throw new LogicException('Relation persistence requires the relation graph planner.');
        }
        $original = collect(JsonRows::hydrate($original, $root, $template, $context))->keyBy(fn ($row) => $row['_brume']['key'])->all();
        $stored = [];
        foreach ($value ?? [] as $row) {
            // This writer consumes validated/trusted values. Validation rebuilds every editable
            // row as an array; semantic object rows are authoritative inactive containers that
            // must retain their unknown keys and legacy identity exactly, including after moves.
            if ($row instanceof \stdClass) {
                $stored[] = $row;

                continue;
            }
            // Conversion carrier only; validation already used the original nullable root.
            $raw = $original[$row['_brume']['key']] ?? [];
            $carrier = new class extends Model {};
            // Seed only persisted raw attributes, preserving unknown and omitted values.
            foreach ($raw as $attribute => $storedValue) {
                if (is_array($storedValue) || $storedValue instanceof \stdClass) {
                    $carrier->mergeCasts([$attribute => 'json']);
                }
                $carrier->setAttribute($attribute, $storedValue);
            }
            foreach ($this->rowForm->fields() as $path => $field) {
                if (! Arr::has($row, $path) || $field->type() === 'display' || ($field->settings()['displayOnly'] ?? false)) {
                    continue;
                }
                foreach ($field->mappedAttributes() ?: [$field->attributeName()] as $attribute) {
                    if (str_contains($attribute, '.')) {
                        $carrier->mergeCasts([explode('.', $attribute, 2)[0] => 'array']);
                    }
                }
                if ($field instanceof Repeater) {
                    $field->fillWithOriginal($carrier, data_get($row, $path), data_get($raw, $field->attributeName(), []), $root, $template.'.*.'.$path, [...$context, ['path' => $template, 'key' => $row['_brume']['key']]]);
                } else {
                    if ($field instanceof Upload && ($field->settings()['multiple'] || $field->extraFields() !== [])) {
                        $carrier->mergeCasts([explode('.', $field->attributeName())[0] => 'json']);
                    }
                    $field->fill($carrier, data_get($row, $path));
                }
            }
            $converted = $carrier->attributesToArray();
            foreach ($carrier->getCasts() as $attribute => $cast) {
                if (in_array($cast, ['array', 'json'], true) && is_string($carrier->getAttributes()[$attribute] ?? null)) {
                    $converted[$attribute] = json_decode($carrier->getAttributes()[$attribute], false, 512, JSON_THROW_ON_ERROR);
                }
            }
            $stored[] = ['_brume' => $row['_brume']] + $converted;
        }
        parent::fill($record, $stored);
    }

    public function impliedRules(): array
    {
        return [...parent::impliedRules(), 'array', 'list'];
    }
}
