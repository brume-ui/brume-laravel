<?php

namespace Brume\Grid\Concerns;

use Brume\Grid\Editor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

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
     * with $rules (the select, radio and checkbox options, a switch's boolean and the editor's
     * bounds are added). With a display() closure the row also carries the raw value for the
     * editor. Only the model's own attributes can be edited, not a relation's (a dotted key).
     *
     * @param  array<int|string, mixed>|string  $rules
     */
    public function editable(?Editor $editor = null, array|string $rules = []): static
    {
        $this->assertKeepsRowId('行內編輯');

        if ($this->primary) {
            throw new LogicException(static::primaryEditableMessage($this->key));
        }

        if (str_contains($this->key, '.')) {
            throw new InvalidArgumentException("欄位「{$this->key}」是關聯欄位：行內編輯（editable()、switch()、switchGroup()）目前只能改模型自己的欄位，關聯欄位的編輯會在之後的版本提供。");
        }

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

        $dotted = array_filter(array_keys($keys), fn (string|int $key) => str_contains((string) $key, '.'));

        if ($dotted !== []) {
            throw new InvalidArgumentException(sprintf('欄位「%s」的 switchGroup() 有關聯欄位（%s）：行內編輯目前只能改模型自己的欄位，關聯欄位的編輯會在之後的版本提供。', $this->key, implode('、', $dotted)));
        }

        $this->editable(Editor::fromSchema(['type' => 'switchGroup', 'keys' => array_map(
            fn (string|int $key, string $label) => ['key' => (string) $key, 'label' => $label],
            array_keys($keys),
            array_values($keys),
        )]));
        $this->switchKeys = $keys;

        return $this;
    }

    protected static function primaryEditableMessage(string $key): string
    {
        return "欄位「{$key}」：primary 欄位點擊會開啟編輯，不能同時行內編輯。請把 primary() 放在另一個欄位，或拿掉行內編輯。";
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
     * @internal
     *
     * @return array<string, string> attribute => label, for switchGroup()
     */
    public function switchKeys(): array
    {
        return $this->switchKeys;
    }

    /**
     * A checkbox saves a list, which only an attribute cast to an array (array, json, collection,
     * object, their encrypted forms, or a cast class) can store.
     *
     * @internal
     *
     * @throws LogicException
     */
    public function assertCheckboxCast(mixed $row): void
    {
        if ($this->editor?->type() !== 'checkbox' || ! $row instanceof Model) {
            return;
        }

        $cast = $row->getCasts()[$this->key] ?? null;
        $listCasts = ['array', 'json', 'json:unicode', 'collection', 'object', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object'];

        if ($cast !== null && (in_array(strtolower($cast), $listCasts, true) || class_exists(Str::before($cast, ':')))) {
            return;
        }

        throw new LogicException(sprintf(
            '欄位「%s」用 checkbox 編輯器，會存成陣列，但 %s 沒有把 %s cast 成 array 或 json：請在模型的 casts() 加上 \'%s\' => \'array\'，或換一種編輯器。',
            $this->key, $row::class, $this->key, $this->key,
        ));
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
