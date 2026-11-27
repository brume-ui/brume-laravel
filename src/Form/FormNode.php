<?php

namespace Brume\Form;

use Brume\Form\Fields\Field;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** A structural node. Field names remain relative; their server-side paths are separate. */
class FormNode
{
    /** @param list<Field|FormNode> $children */
    public function __construct(public readonly string $type, protected array $attributes = [], protected array $children = []) {}

    public function mapFields(Closure $map): self
    {
        return new self($this->type, $this->attributes, array_map(fn ($node) => $node instanceof Field ? $map($node) : $node->mapFields($map), $this->children));
    }

    /** Null when the node chooses to leave itself out of the page (a button whose closure URL is unusable). */
    public function toSchema(?Model $record = null, ?Model $root = null): ?array
    {
        if ($this->type === 'row' && app()->environment('local')) {
            $width = array_sum(array_map(fn (Field $field) => $field->gridWidth(), $this->children));
            if ($width > 12) {
                throw new InvalidArgumentException('Form row widths exceed 12.');
            }
        }
        if ($this->type === 'html') {
            $html = $this->attributes['html'];
            $html = $html instanceof Closure ? $html($record) : $html;

            return ['type' => 'html', 'html' => $html instanceof Htmlable ? $html->toHtml() : (string) $html] + array_diff_key($this->attributes, ['html' => 1]);
        }
        $children = self::schemas($this->children, $record, $root);
        if ($this->type === 'column') {
            return $this->attributes + ['children' => $children];
        }

        return ['type' => $this->type] + $this->attributes + ($this->type === 'divider' ? [] : [$this->type === 'columns' ? 'columns' : 'children' => $children]);
    }

    /** @param list<Field|FormNode> $nodes */
    public static function schemas(array $nodes, ?Model $record, ?Model $root): array
    {
        $schemas = [];
        foreach ($nodes as $node) {
            if ($node instanceof Field && $node->omittedFromSchema($record)) {
                continue;
            }
            $schema = $node instanceof Field ? ['type' => 'field', 'field' => $node->schemaFor($record, $root)] : $node->toSchema($record, $root);
            if ($schema !== null) {
                $schemas[] = $schema;
            }
        }

        return $schemas;
    }
}
