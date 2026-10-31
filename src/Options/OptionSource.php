<?php

namespace Brume\Options;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Declaration metadata only. Query/response execution is owned by the scoped options service. */
class OptionSource
{
    protected array $configuration = [];

    protected ?Closure $sectionScope = null;

    final protected function __construct(protected string|Closure $modelOrQuery) {}

    public static function make(string|Closure $modelOrQuery): static
    {
        if (is_string($modelOrQuery) && ! is_subclass_of($modelOrQuery, Model::class)) {
            throw new InvalidArgumentException('Option source must be an Eloquent model or scoped query closure.');
        }

        return new static($modelOrQuery);
    }

    public static function assertAttribute(string $attribute): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $attribute)) {
            throw new InvalidArgumentException('Option attributes must be column identifiers.');
        }
    }

    public function label(string $attribute): static
    {
        self::assertAttribute($attribute);
        $this->configuration['label'] = $attribute;

        return $this;
    }

    public function depth(string $attribute): static
    {
        self::assertAttribute($attribute);
        $this->configuration['depth'] = $attribute;

        return $this;
    }

    public function search(array $attributes): static
    {
        foreach ($attributes as $attribute) {
            self::assertAttribute($attribute);
        }
        $this->configuration['search'] = array_values($attributes);

        return $this;
    }

    public function columns(array $attributeLabels): static
    {
        foreach ($attributeLabels as $attribute => $label) {
            self::assertAttribute($attribute);
            if (! is_string($label)) {
                throw new InvalidArgumentException('Column labels must be strings.');
            }
        }
        $this->configuration['columns'] = $attributeLabels;

        return $this;
    }

    public function sections(array $sectionLabels, Closure $scope): static
    {
        foreach ($sectionLabels as $key => $label) {
            if ((string) $key === '' || ! is_string($label)) {
                throw new InvalidArgumentException('Sections require nonempty keys and string labels.');
            }
        }
        $this->configuration['sections'] = $sectionLabels;
        $this->sectionScope = $scope;

        return $this;
    }

    public function configuration(): array
    {
        return $this->configuration;
    }

    public function modelOrQuery(): string|Closure
    {
        return $this->modelOrQuery;
    }

    public function sectionScope(): ?Closure
    {
        return $this->sectionScope;
    }
}
