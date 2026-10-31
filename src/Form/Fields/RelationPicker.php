<?php

namespace Brume\Form\Fields;

use Brume\Form\Form;
use Brume\Form\FormSnapshot;
use Brume\Options\OptionSource;
use Closure;
use Illuminate\Database\Eloquent\Model;
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

    public function __construct(string $name, string $label, OptionSource $source, protected Form $extraForm)
    {
        parent::__construct($name, $label);
        $this->remote($source);
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

    public function relationship(Model $parent): HasMany|BelongsToMany
    {
        if ($this->relationName === null || ! method_exists($parent, $this->relationName)) {
            throw new InvalidArgumentException('Unknown picker relation.');
        }
        $relation = $parent->{$this->relationName}();
        if (! $relation instanceof HasMany && ! $relation instanceof BelongsToMany) {
            throw new InvalidArgumentException('Picker requires HasMany or BelongsToMany.');
        }
        if ($relation instanceof BelongsToMany && $this->association !== null) {
            throw new InvalidArgumentException('associateBy requires an intermediary HasMany.');
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
        return $this->remoteSchema() + parent::toSchema($record) + ['fields' => $this->extraForm->nodesSchema(null, $root)];
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
