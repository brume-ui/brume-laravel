<?php

namespace Brume\Form\Fields;

use Brume\Form\Form;
use Brume\Form\FormSnapshot;
use Brume\Options\OptionSource;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use LogicException;

class RelationPicker extends Field
{
    use HasRemoteOptions;

    protected array $schema = ['multiple' => true];

    protected ?string $relationName = null;

    protected ?string $association = null;

    protected bool $restore = false;

    protected ?string $orderAttribute = null;

    public function __construct(string $name, string $label, OptionSource $source, protected Form $extraForm)
    {
        parent::__construct($name, $label);
        $this->remote($source);
    }

    public function __clone()
    {
        $this->extraForm = clone $this->extraForm;
    }

    public function type(): string
    {
        return 'relation';
    }

    public function rowForm(): Form
    {
        return $this->extraForm;
    }

    public function multiple(bool $on): static
    {
        $this->schema['multiple'] = $on;

        return $this;
    }

    public function relation(string $name): static
    {
        OptionSource::assertAttribute($name);
        $this->relationName = $name;

        return $this;
    }

    public function relationName(): ?string
    {
        return $this->relationName;
    }

    public function associateBy(string $attribute): static
    {
        OptionSource::assertAttribute($attribute);
        $this->association = $attribute;

        return $this;
    }

    public function associationAttribute(): ?string
    {
        return $this->association;
    }

    /** Persist selection order on owned intermediary/pivot storage, never on source models. */
    public function orderColumn(string $attribute): static
    {
        OptionSource::assertAttribute($attribute);
        $this->orderAttribute = $attribute;

        return $this;
    }

    public function orderAttribute(): ?string
    {
        return $this->orderAttribute;
    }

    public function restoreRemoved(bool $on = true): static
    {
        $this->restore = $on;

        return $this;
    }

    public function restoresRemoved(): bool
    {
        return $this->restore;
    }

    public function extra(Closure $fields): static
    {
        $fields($this->extraForm);
        $this->extraForm->assertReservedFields(['id', '_brume']);

        return $this;
    }

    public function relationship(Model $parent): HasMany|BelongsToMany|BelongsTo
    {
        if ($this->relationName === null || ! method_exists($parent, $this->relationName)) {
            throw new InvalidArgumentException('Unknown picker relation.');
        }
        $relation = $parent->{$this->relationName}();
        if (! $relation instanceof HasMany && ! $relation instanceof BelongsToMany && ! $relation instanceof BelongsTo) {
            throw new InvalidArgumentException('Picker requires BelongsTo, HasMany or BelongsToMany.');
        }
        if (! $relation instanceof HasMany && $this->association !== null) {
            throw new InvalidArgumentException('associateBy requires an intermediary HasMany.');
        }
        if ($relation instanceof BelongsTo && $this->schema['multiple']) {
            throw new InvalidArgumentException('BelongsTo picker requires multiple(false).');
        }
        if ($this->orderAttribute !== null) {
            if (! $this->schema['multiple'] || (! $relation instanceof BelongsToMany && ! ($relation instanceof HasMany && $this->association !== null))) {
                throw new InvalidArgumentException('Picker ordering requires multiple owned intermediary or pivot selections.');
            }
            $identity = $relation instanceof BelongsToMany
                ? [$relation->getForeignPivotKeyName(), $relation->getRelatedPivotKeyName(), $relation->newPivot()->getKeyName(), $relation->createdAt(), $relation->updatedAt()]
                : [$relation->getRelated()->getKeyName(), $relation->getForeignKeyName(), $this->association];
            if (in_array($this->orderAttribute, $identity, true)) {
                throw new InvalidArgumentException('Picker ordering cannot replace relation identity.');
            }
            foreach ($this->extraForm->fields() as $field) {
                foreach ($field->mappedAttributes() ?: [$field->attributeName()] as $attribute) {
                    if (explode('.', $attribute)[0] === $this->orderAttribute) {
                        throw new InvalidArgumentException('Picker ordering is metadata, not an editable extra.');
                    }
                }
            }
            $column = $relation instanceof BelongsToMany ? $relation->qualifyPivotColumn($this->orderAttribute) : $relation->getRelated()->qualifyColumn($this->orderAttribute);
            $relation->reorder($column)->orderBy($relation->getRelated()->getQualifiedKeyName());
        }
        if ($relation instanceof BelongsToMany) {
            $protected = [$relation->getForeignPivotKeyName(), $relation->getRelatedPivotKeyName(), $relation->newPivot()->getKeyName(), $relation->createdAt(), $relation->updatedAt()];
            foreach ($this->extraForm->fields() as $field) {
                foreach ($field->mappedAttributes() ?: [$field->attributeName()] as $attribute) {
                    if (in_array($attribute, $protected, true)) {
                        throw new InvalidArgumentException('Pivot identity and timestamps cannot be editable extras.');
                    }
                }
            }
            $columns = [];
            foreach ($this->extraForm->fields() as $field) {
                $columns = [...$columns, ...($field->mappedAttributes() ?: [$field->attributeName()])];
            }
            $relation->withPivot($this->orderAttribute === null ? $columns : [...$columns, $this->orderAttribute]);
        }
        if ($relation instanceof HasMany && $this->association === null) {
            $column = collect($relation->getRelated()->getConnection()->getSchemaBuilder()->getColumns($relation->getRelated()->getTable()))->firstWhere('name', $relation->getForeignKeyName());
            if (! ($column['nullable'] ?? false)) {
                throw new InvalidArgumentException('Direct HasMany picker requires a nullable foreign key for deselection.');
            }
        }
        if ($this->restore && ($this->association === null || ! in_array(SoftDeletes::class, class_uses_recursive($relation->getRelated()), true))) {
            throw new InvalidArgumentException('restoreRemoved requires a soft-deletable intermediary relation.');
        }

        return $relation;
    }

    public function toSchema(?Model $record = null): array
    {
        return $this->schemaFor($record, $record);
    }

    public function schemaFor(?Model $record, ?Model $root): array
    {
        return ($this->extraForm->fields() !== [] ? ['hydrateAuthority' => true] : []) + $this->remoteSchema() + parent::toSchema($record) + ['fields' => $this->extraForm->nodesSchema(null, $root)];
    }

    public function valueFor(?Model $record): mixed
    {
        return (new FormSnapshot)->value($this, $record, root: $record);
    }

    public function fill(Model $record, mixed $value): void
    {
        throw new LogicException('Relation persistence requires the relation graph planner.');
    }
}
