<?php

namespace Brume\Grid\Concerns;

use Brume\Grid\Editor;
use InvalidArgumentException;

/**
 * Inline editing of a column, saved through the Route::brumeGrid cell endpoint.
 */
trait HasEditing
{
    protected ?Editor $editor = null;

    /** @var array<int|string, mixed>|string Laravel validation rules for the new value */
    protected array|string $editRules = [];

    /** @var array<string, string> attribute => label, for switchGroup() */
    protected array $switchKeys = [];

    /**
     * Edit the cell in place with $editor (a text input by default), validating the new value
     * with $rules. With a display() closure the row also carries the raw value for the editor.
     *
     * @param  array<int|string, mixed>|string  $rules
     */
    public function editable(?Editor $editor = null, array|string $rules = []): static
    {
        $this->editor = $editor ?? Editor::text();
        $this->editRules = $rules;
        $this->switchKeys = [];

        return $this;
    }

    /**
     * A switch that saves on click; the value is sent as a boolean.
     *
     * @param  array<int|string, mixed>|string  $rules
     */
    public function switch(array|string $rules = [], ?string $onLabel = null, ?string $offLabel = null): static
    {
        return $this->editable(Editor::fromSchema(['type' => 'switch']
            + ($onLabel !== null ? ['onLabel' => $onLabel] : [])
            + ($offLabel !== null ? ['offLabel' => $offLabel] : [])), $rules);
    }

    /**
     * A switch for each of several boolean attributes, in one cell. The cell value is
     * { attribute: bool } with one entry per declared attribute; the column key only names the cell.
     *
     * @param  array<string, string>  $keys  attribute => label
     */
    public function switchGroup(array $keys): static
    {
        if ($keys === [] || array_is_list($keys)) {
            throw new InvalidArgumentException('switchGroup() 需要至少一個欄位，格式是欄位 => 標籤，例如 [\'is_featured\' => \'精選\']。');
        }

        $this->editable(Editor::fromSchema(['type' => 'switchGroup', 'keys' => array_map(
            fn (string|int $key, string $label) => ['key' => (string) $key, 'label' => $label],
            array_keys($keys),
            array_values($keys),
        )]));
        $this->switchKeys = $keys;

        return $this;
    }

    /**
     * @internal
     */
    public function isEditable(): bool
    {
        return $this->editor !== null;
    }

    /**
     * @internal
     */
    public function editor(): ?Editor
    {
        return $this->editor;
    }

    /**
     * @internal
     *
     * @return array<int|string, mixed>|string
     */
    public function editRules(): array|string
    {
        return $this->editRules;
    }

    /**
     * The value the editor works on: { attribute: bool } for switchGroup, a boolean for switch.
     */
    protected function editedValue(mixed $row, mixed $value): mixed
    {
        return match ($this->editor?->type()) {
            'switchGroup' => array_map(
                fn (string|int $key) => (bool) data_get($row, (string) $key),
                array_combine(array_keys($this->switchKeys), array_keys($this->switchKeys)),
            ),
            'switch' => (bool) $value,
            default => $value,
        };
    }
}
