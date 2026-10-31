<?php

namespace Brume\Form;

use Brume\Form\Fields\Decimal;
use InvalidArgumentException;
use JsonSerializable;

final readonly class Conditions implements JsonSerializable
{
    private function __construct(private array $ast) {}

    public static function eq(ConditionPath|array $field, string|int|float|bool|null $value): self
    {
        return self::leaf('eq', $field, ['value' => $value]);
    }

    public static function ne(ConditionPath|array $field, string|int|float|bool|null $value): self
    {
        return self::leaf('ne', $field, ['value' => $value]);
    }

    public static function in(ConditionPath|array $field, array $values): self
    {
        return self::fromArray(['op' => 'in', 'field' => self::path($field)->jsonSerialize(), 'values' => $values]);
    }

    public static function empty(ConditionPath|array $field): self
    {
        return self::leaf('empty', $field);
    }

    public static function all(self ...$conditions): self
    {
        return new self(['op' => 'all', 'conditions' => array_map(fn ($c) => $c->ast, $conditions)]);
    }

    public static function any(self ...$conditions): self
    {
        return new self(['op' => 'any', 'conditions' => array_map(fn ($c) => $c->ast, $conditions)]);
    }

    public static function not(self $condition): self
    {
        return new self(['op' => 'not', 'condition' => $condition->ast]);
    }

    private static function leaf(string $op, ConditionPath|array $field, array $rest = []): self
    {
        $field = self::path($field);
        foreach ($rest as $value) {
            if (is_float($value) && ! is_finite($value)) {
                throw new InvalidArgumentException('Condition numbers must be finite.');
            }
        }
        if (str_contains($field->path, '*')) {
            throw new InvalidArgumentException('Conditions require scalar paths.');
        }

        return new self(['op' => $op, 'field' => $field->jsonSerialize()] + $rest);
    }

    public static function fromArray(array $ast): self
    {
        $op = $ast['op'] ?? '';
        $expected = match ($op) {
            'all','any' => ['op', 'conditions'], 'not' => ['op', 'condition'], 'eq','ne' => ['op', 'field', 'value'], 'in' => ['op', 'field', 'values'], 'empty' => ['op', 'field'], default => []
        };
        $keys = array_keys($ast);
        sort($keys);
        sort($expected);
        if ($keys !== $expected || $expected === []) {
            throw new InvalidArgumentException('Invalid condition shape.');
        }
        if (in_array($op, ['all', 'any'], true) && (! is_array($ast['conditions']) || ! array_is_list($ast['conditions']))) {
            throw new InvalidArgumentException('Conditions must be a list.');
        }
        if ($op === 'in' && (! is_array($ast['values']) || ! array_is_list($ast['values']))) {
            throw new InvalidArgumentException('Condition values must be a list.');
        }
        if (isset($ast['field'])) {
            $pathKeys = array_keys($ast['field']);
            sort($pathKeys);
            if ($pathKeys !== ['path', 'scope']) {
                throw new InvalidArgumentException('Invalid condition path shape.');
            }
        }

        if (in_array($op, ['all', 'any'], true)) {
            return self::$op(...array_map(self::fromArray(...), $ast['conditions']));
        }
        if ($op === 'not') {
            return self::not(self::fromArray($ast['condition']));
        }
        if (! in_array($op, ['eq', 'ne', 'in', 'empty'], true)) {
            throw new InvalidArgumentException('Invalid condition operation.');
        }
        $path = new ConditionPath($ast['field']['scope'], $ast['field']['path']);
        foreach ($op === 'in' ? $ast['values'] : ($op === 'empty' ? [] : [$ast['value']]) as $value) {
            if (($value !== null && ! is_scalar($value)) || (is_float($value) && ! is_finite($value))) {
                throw new InvalidArgumentException('Condition operands must be JSON primitives.');
            }
        }

        return self::leaf($op, $path, match ($op) {
            'in' => ['values' => $ast['values']], 'empty' => [], default => ['value' => $ast['value']]
        });
    }

    public function paths(): array
    {
        if (isset($this->ast['conditions'])) {
            return array_merge([], ...array_map(fn ($c) => self::fromArray($c)->paths(), $this->ast['conditions']));
        }
        if ($this->ast['op'] === 'not') {
            return self::fromArray($this->ast['condition'])->paths();
        }

        return [new ConditionPath($this->ast['field']['scope'], $this->ast['field']['path'])];
    }

    public static function root(string $path): array
    {
        return ConditionPath::root($path)->jsonSerialize();
    }

    public static function row(string $path): array
    {
        return ConditionPath::row($path)->jsonSerialize();
    }

    public static function path(ConditionPath|array $path): ConditionPath
    {
        return $path instanceof ConditionPath ? $path : new ConditionPath($path['scope'], $path['path']);
    }

    public function toArray(): array
    {
        return $this->ast;
    }

    public function jsonSerialize(): array
    {
        return $this->ast;
    }

    public static function canonical(array $def, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [true, $value];
        }
        $type = $def['type'] ?? '';
        if (in_array($type, ['decimal', 'currency'], true)) {
            $scale = $def['decimals'];
            $valid = is_string($value) && preg_match('/^[+-]?[0-9]+(?:\.[0-9]{0,'.$scale.'})?$/D', $value);

            return [(bool) $valid, $valid ? (new Decimal('v'))->decimals($scale)->default($value)->valueFor(null) : null];
        }
        if ($type === 'number') {
            return [is_numeric($value) && ! is_bool($value) && is_finite((float) $value), is_numeric($value) ? $value + 0 : $value];
        }
        if ($type === 'switch') {
            return [is_bool($value), $value];
        }
        $object = fn ($item) => $item instanceof \stdClass || (is_array($item) && ! array_is_list($item));
        if ($type === 'repeater') {
            return [is_array($value) && array_is_list($value) && count(array_filter($value, $object)) === count($value), $value];
        }
        if ($type === 'relation') {
            $selected = fn ($item) => $object($item) && is_string(data_get($item, 'id')) && data_get($item, 'id') !== '';
            $valid = ($def['multiple'] ?? true)
                ? is_array($value) && array_is_list($value) && count(array_filter($value, $selected)) === count($value)
                : $selected($value);

            return [$valid, $value];
        }
        if (in_array($type, ['multipleSelect', 'checkbox', 'tags'], true)) {
            return [is_array($value) && array_is_list($value) && count(array_filter($value, 'is_string')) === count($value), $value];
        }
        if (in_array($type, ['text', 'textarea', 'email', 'url', 'mobile', 'password', 'select', 'radio', 'date', 'datetime'], true)) {
            return [is_string($value), $value];
        }

        return [true, $value];
    }

    public function evaluate(array $values, ?string $rowPath = null, array $fields = [], array $invalidPaths = []): bool
    {
        $defs = [];
        foreach ($fields as $key => $field) {
            $defs[is_string($key) ? $key : $field['name']] = $field;
        }
        foreach ($this->paths() as $path) {
            $resolved = $path->resolve($rowPath);
            if (in_array($resolved, $invalidPaths, true) || ! self::canonical($defs[$resolved] ?? [], data_get($values, $resolved))[0]) {
                return true;
            }
        }
        $equal = fn ($a, $b) => (is_int($a) || is_float($a)) && (is_int($b) || is_float($b)) ? $a == $b : $a === $b;
        $evaluate = function (array $ast) use (&$evaluate, $values, $rowPath, $defs, $equal): bool {
            $op = $ast['op'];
            if ($op === 'not') {
                return ! $evaluate($ast['condition']);
            }
            if ($op === 'all') {
                foreach ($ast['conditions'] as $c) {
                    if (! $evaluate($c)) {
                        return false;
                    }
                }

                return true;
            }
            if ($op === 'any') {
                foreach ($ast['conditions'] as $c) {
                    if ($evaluate($c)) {
                        return true;
                    }
                }

                return false;
            }
            $path = (new ConditionPath($ast['field']['scope'], $ast['field']['path']))->resolve($rowPath);
            $raw = data_get($values, $path);
            if ($op === 'empty') {
                return $raw === null || $raw === '' || $raw === [];
            }
            $def = $defs[$path] ?? [];
            $value = self::canonical($def, $raw)[1];
            $operand = fn ($v) => in_array($def['type'] ?? '', ['decimal', 'currency'], true) && is_string($v) ? (self::canonical($def, $v)[1] ?? $v) : $v;
            if ($op === 'in') {
                foreach ($ast['values'] as $v) {
                    if ($equal($value, $operand($v))) {
                        return true;
                    }
                }

                return false;
            }

            return $op === 'eq' ? $equal($value, $operand($ast['value'])) : ! $equal($value, $operand($ast['value']));
        };

        return $evaluate($this->ast);
    }
}
