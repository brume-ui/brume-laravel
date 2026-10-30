<?php

namespace Brume\Form;

use Brume\Form\Fields\Field;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** A structural node. Field names remain relative; their server-side paths are separate. */
class FormNode
{
    /** @param list<Field|FormNode> $children */
    public function __construct(public readonly string $type, protected array $attributes = [], protected array $children = []) {}

    public function toSchema(?Model $record = null): array
    {
        if ($this->type === 'row' && app()->environment('local')) {
            $width = array_sum(array_map(fn (Field $field) => $field->gridWidth(), $this->children));
            if ($width > 12) {
                throw new InvalidArgumentException('Form row widths exceed 12.');
            }
        }
        $children = array_map(fn (Field|self $node) => $node instanceof Field ? ['type' => 'field', 'field' => $node->toSchema($record)] : $node->toSchema($record), $this->children);
        if ($this->type === 'column') {
            return $this->attributes + ['children' => $children];
        }

        return ['type' => $this->type] + $this->attributes + ($this->type === 'divider' ? [] : [$this->type === 'columns' ? 'columns' : 'children' => $children]);
    }
}
