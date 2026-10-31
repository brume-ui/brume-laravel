<?php

namespace Brume\Options;

use Brume\Grid\Query\Like;
use Brume\Grid\RowLookup;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** A server-declared source shared by Form, Grid and persistence eligibility. */
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

    /** A fresh scoped builder; callers may add eligibility constraints without changing the source. */
    public function query(OptionContext $context): Builder
    {
        $query = is_string($this->modelOrQuery) ? $this->modelOrQuery::query() : ($this->modelOrQuery)($context);
        if (! $query instanceof Builder) {
            throw new InvalidArgumentException('Option source closure must return an Eloquent Builder.');
        }
        $model = $query->getModel();
        $label = $this->configuration['label'] ?? throw new InvalidArgumentException('Option source requires a label attribute.');
        $attributes = [$model->getKeyName(), $label, ...($this->configuration['search'] ?? [$label]), ...array_keys($this->configuration['columns'] ?? [])];
        if (isset($this->configuration['depth'])) {
            $attributes[] = $this->configuration['depth'];
        }
        foreach (array_unique($attributes) as $attribute) {
            self::assertAttribute($attribute);
            if (in_array($attribute, $model->getHidden(), true) || ($model->getVisible() !== [] && ! in_array($attribute, $model->getVisible(), true))) {
                throw new InvalidArgumentException('Option source uses a hidden attribute: '.$attribute);
            }
        }

        return clone $query;
    }

    /** Strict new-source protocol; legacy Grid RemoteOptions deliberately keeps its tolerant API. */
    public function response(OptionContext $context, array $parameters, bool $picker = false): array
    {
        $page = $this->positive($parameters, 'page', 1, 999999);
        $perPage = $this->positive($parameters, 'per_page', 20, 50);
        $q = $parameters['q'] ?? '';
        if (! is_string($q) || strlen($q) > 1000) {
            $this->invalid('q');
        }
        $query = $this->query($context);
        $sections = $this->configuration['sections'] ?? [];
        $section = $parameters['section'] ?? array_key_first($sections);
        if ($section !== null) {
            if ((! is_string($section) && ! is_int($section)) || ! array_key_exists($section, $sections)) {
                $this->invalid('section');
            }
            ($this->sectionScope)($query, (string) $section, $context);
        }
        $lookup = new RowLookup($query);
        if (array_key_exists('value', $parameters)) {
            $values = $parameters['value'];
            if (! is_array($values) || ! array_is_list($values) || count($values) > 100) {
                $this->invalid('value');
            }
            $ids = [];
            foreach ($values as $value) {
                $id = $lookup->normalizeId($value);
                if ($id === null || in_array($id, $ids, true)) {
                    $this->invalid('value');
                }
                $ids[] = $id;
            }
            $found = $query->whereKey($ids)->get()->keyBy(fn (Model $row) => (string) $row->getKey());
            $rows = [];
            foreach ($ids as $id) {
                // Exact key comparison also protects case-insensitive database collations.
                $row = $found->get((string) $id);
                if ($row !== null && (string) $row->getKey() === (string) $id) {
                    $rows[] = $row;
                }
            }
            $pagination = ['page' => 1, 'perPage' => max(1, count($ids)), 'hasMore' => false];
        } else {
            if (trim($q) !== '') {
                $query->where(function (Builder $search) use ($q) {
                    foreach ($this->configuration['search'] ?? [$this->configuration['label']] as $attribute) {
                        Like::contains($search, $search->qualifyColumn($attribute), trim($q), boolean: 'or');
                    }
                });
            }
            $rows = $query->orderBy($query->qualifyColumn($this->configuration['label']))
                ->orderBy($query->getModel()->getQualifiedKeyName())->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();
            $pagination = ['page' => $page, 'perPage' => $perPage, 'hasMore' => $rows->count() > $perPage];
            $rows = $rows->take($perPage)->all();
        }
        $result = ['data' => array_map(fn (Model $row) => $this->option($row, $picker), $rows), 'pagination' => $pagination];
        if ($picker) {
            foreach (['columns', 'sections'] as $key) {
                if (isset($this->configuration[$key])) {
                    $result[$key] = [];
                    foreach ($this->configuration[$key] as $attribute => $label) {
                        $result[$key][] = ['key' => (string) $attribute, 'label' => $label];
                    }
                }
            }
        }

        return $result;
    }

    private function option(Model $row, bool $picker): array
    {
        $option = ['value' => (string) $row->getKey(), 'label' => (string) $row->getAttribute($this->configuration['label'])];
        if (isset($this->configuration['depth'])) {
            $depth = $row->getAttribute($this->configuration['depth']);
            if (filter_var($depth, FILTER_VALIDATE_INT) === false || $depth < 0) {
                throw new InvalidArgumentException('Option depth must be a nonnegative integer.');
            }
            $option['depth'] = (int) $depth;
        }
        if ($picker && isset($this->configuration['columns'])) {
            $option['columns'] = (object) [];
            foreach ($this->configuration['columns'] as $attribute => $label) {
                $option['columns']->{$attribute} = $row->getAttribute($attribute);
            }
            if ($this->configuration['columns'] !== []) {
                $option['columns'] = (array) $option['columns'];
            }
        }

        return $option;
    }

    private function positive(array $parameters, string $key, int $default, int $max): int
    {
        if (! array_key_exists($key, $parameters)) {
            return $default;
        }
        $value = $parameters[$key];
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9][0-9]{0,8}$/D', (string) $value) || (int) $value > $max) {
            $this->invalid($key);
        }

        return (int) $value;
    }

    private function invalid(string $key): never
    {
        throw ValidationException::withMessages([$key => 'Invalid options parameter.']);
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
