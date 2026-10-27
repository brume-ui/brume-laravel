<?php

namespace Brume\Support;

use InvalidArgumentException;

/**
 * Builds the `{type:'component', name, props?}` reference the frontend resolves from its registry.
 */
final class ComponentRef
{
    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    public static function make(string $name, array $props = []): array
    {
        if ($props !== [] && array_is_list($props)) {
            throw new InvalidArgumentException("元件「{$name}」的 props 必須是具名的陣列，例如 ['tone' => 'success']，不能是清單。");
        }

        // An empty PHP array encodes as `[]`, which the schema rejects for an object, so omit it.
        return ['type' => 'component', 'name' => $name] + ($props === [] ? [] : ['props' => $props]);
    }
}
