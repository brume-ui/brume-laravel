<?php

namespace Brume\Grid\Editing;

use Brume\Grid\Column;
use Brume\Grid\Grid;
use Brume\Grid\RowLookup;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answers endpoints.cell: a CellUpdateRequest { column, value } for the row $id, with a
 * CellUpdateResponse { row }.
 *
 * Only a column declared with editable(), switch() or switchGroup() is written, and only its own
 * attribute (a switchGroup's declared attributes), whatever the model's $fillable says. The value
 * is checked with the column's rules plus what its editor implies: one of the declared options of a
 * select or radio (each item of a checkbox), matched strictly on the option's string form and saved
 * as that option; a switch's boolean; a switchGroup's declared keys; a number's bounds (and a whole
 * number when the attribute is cast to int or the step is whole); a text's maximum length. Except
 * for a text or textarea, a blank string counts as null, so it passes only a column whose own rules
 * say nullable, whether or not the app converts empty strings to null. Every error is keyed by the
 * column key. The row comes from the grid's query (see Grid::rows()), so an id the list could never
 * show is not found.
 */
class CellUpdater
{
    /**
     * @return array{row: array<string, mixed>}
     *
     * @throws ValidationException 422: a body that is not a CellUpdateRequest, a column that is
     *                             not edited inline, an id that cannot be a key, or a refused value
     * @throws NotFoundHttpException 404: the id is not in the grid's query
     */
    public function update(Grid $grid, Request $request, string $id): array
    {
        $rows = $grid->rows();

        Validator::make($request->all(), [
            'column' => ['required', 'string'],
            'value' => ['present'],
        ])->validate();

        $column = $grid->editableColumn($request->input('column'));

        if ($column === null) {
            throw ValidationException::withMessages(['column' => __('brume::grid.cell.not_editable')]);
        }

        $key = $this->validId($rows, $id);
        $value = $this->validValue($column, $request->input('value'), $rows->query()->getModel());

        $model = $rows->find([$key])?->first();

        if ($model === null) {
            throw new NotFoundHttpException(__('brume::grid.cell.not_found'));
        }

        // Refuse before writing, as the list does when it renders the row.
        $column->assertCheckboxCast($model);

        match ($column->editor()->type()) {
            'switchGroup' => array_walk($value, fn (mixed $on, string|int $attribute) => $model->setAttribute((string) $attribute, $this->toBoolean($on))),
            'switch' => $model->setAttribute($column->key(), $value === null ? null : $this->toBoolean($value)),
            default => $model->setAttribute($column->key(), $value),
        };

        $model->save();

        // Read back through the grid's query, so its selects and eager loads are there for the
        // display closures; a row the edit moved out of the query is answered as saved.
        return ['row' => $grid->rowFor($rows->find([$key])?->first() ?? $model)];
    }

    /**
     * @throws ValidationException
     */
    protected function validId(RowLookup $rows, string $id): int|string
    {
        return $rows->normalizeId($id)
            ?? throw ValidationException::withMessages(['id' => __('brume::grid.invalid_id')]);
    }

    /**
     * The value to save: checked, with a select, radio or checkbox value as the declared option.
     *
     * @throws ValidationException with every message under the column key
     */
    protected function validValue(Column $column, mixed $value, Model $model): mixed
    {
        $key = $column->key();
        $type = $column->editor()->type();
        $options = array_column($column->editor()->toSchema()['options'] ?? [], 'value');

        // Laravel runs no rule but required-like ones on a blank string.
        if (! in_array($type, ['text', 'textarea'], true)) {
            $value = $this->blankToNull($value);
        }

        $validator = Validator::make([$key => $value], $this->rules($column, $model, $options), [], $this->attributeNames($column));

        if ($validator->fails()) {
            throw ValidationException::withMessages([$key => array_values(array_unique($validator->errors()->all()))]);
        }

        return match (true) {
            $value === null => null,
            in_array($type, ['select', 'radio'], true) => $this->matchOption($options, $value),
            $type === 'checkbox' => array_map(fn (mixed $item) => $this->matchOption($options, $item), $value),
            default => $value,
        };
    }

    /**
     * A blank string as null, also inside a list or a switchGroup's object.
     */
    protected function blankToNull(mixed $value): mixed
    {
        return match (true) {
            is_string($value) && trim($value) === '' => null,
            is_array($value) => array_map(fn (mixed $item) => $this->blankToNull($item), $value),
            default => $value,
        };
    }

    /**
     * The declared option $value names: a string or int whose string form is an option value
     * exactly ("01" is not "1"; true and 1.0 are no option), else null.
     *
     * @param  list<string>  $options
     */
    protected function matchOption(array $options, mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return in_array((string) $value, $options, true) ? (string) $value : null;
    }

    /**
     * The column's rules with those its editor implies. A select, radio or checkbox value must be
     * a declared option (see matchOption()); a number must be whole when $model casts the
     * attribute to int or the editor's step is whole.
     *
     * @param  list<string>  $options
     * @return array<string, list<mixed>>
     */
    protected function rules(Column $column, Model $model, array $options): array
    {
        $key = $column->key();
        $editor = $column->editor()->toSchema();
        $declared = $column->editRules();
        $declared = is_string($declared) ? array_values(array_filter(explode('|', $declared), fn (string $rule) => $rule !== '')) : array_values($declared);
        $whole = $model->hasCast($key, ['int', 'integer'])
            || (isset($editor['step']) && floor($editor['step']) === (float) $editor['step']);

        return match ($editor['type']) {
            'select', 'radio' => [$key => [...$declared, $this->optionRule($options)]],
            'checkbox' => [$key => ['array', 'list', ...$declared, $this->optionsRule($options)]],
            'switch' => [$key => [...$declared, 'boolean']],
            'switchGroup' => [$key => ['required', 'array:'.implode(',', array_keys($column->switchKeys()))]]
                + array_fill_keys(array_map(fn (string|int $attribute) => "{$key}.{$attribute}", array_keys($column->switchKeys())), ['boolean']),
            'number' => [$key => [
                ...$declared, 'numeric',
                ...($whole ? ['integer'] : []),
                ...(isset($editor['min']) ? ['min:'.$editor['min']] : []),
                ...(isset($editor['max']) ? ['max:'.$editor['max']] : []),
            ]],
            default => [$key => [
                ...$declared, $this->textRule(),
                ...(isset($editor['maxLength']) ? ['max:'.$editor['maxLength']] : []),
            ]],
        };
    }

    /**
     * @param  list<string>  $options
     */
    protected function optionRule(array $options): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($options) {
            if ($this->matchOption($options, $value) === null) {
                $fail(__('validation.in'));
            }
        };
    }

    /**
     * Each item of a list is a declared option (a value that is not a list is refused by 'array').
     *
     * @param  list<string>  $options
     */
    protected function optionsRule(array $options): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($options) {
            if (is_array($value) && array_filter($value, fn (mixed $item) => $this->matchOption($options, $item) === null) !== []) {
                $fail(__('validation.in'));
            }
        };
    }

    /**
     * A text or textarea takes a scalar (or null, which the column's rules may refuse), not a
     * list or an object.
     */
    protected function textRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_scalar($value) && $value !== null) {
                $fail(__('brume::grid.cell.not_text'));
            }
        };
    }

    /**
     * The column's heading in the messages, a switchGroup attribute's own label.
     *
     * @return array<string, string>
     */
    protected function attributeNames(Column $column): array
    {
        $key = $column->key();
        $names = [$key => $column->heading(), "{$key}.*" => $column->heading()];

        foreach ($column->switchKeys() as $attribute => $label) {
            $names["{$key}.{$attribute}"] = $label;
        }

        return $names;
    }

    /**
     * A value the boolean rule accepted (true, false, 1, 0, '1', '0') as a bool.
     */
    protected function toBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }
}
