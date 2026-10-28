<?php

namespace Brume\Grid\Editing;

use Brume\Grid\Column;
use Brume\Grid\Grid;
use Brume\Grid\RowLookup;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answers endpoints.cell: a CellUpdateRequest { column, value } for the row $id, with a
 * CellUpdateResponse { row }.
 *
 * Only a column declared with editable(), switch() or switchGroup() is written, and only its own
 * attribute (a switchGroup's declared attributes), whatever the model's $fillable says. The value
 * is checked with the column's rules plus what its editor implies: the declared options of a select,
 * radio or checkbox (Rule::in), a switch's boolean, a switchGroup's declared keys, a number's bounds
 * and a text's maximum length. Every error is keyed by the column key. The row comes from the
 * grid's query (see Grid::rows()), so an id the list could never show is not found.
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
        $value = $this->validValue($column, $request->input('value'));

        $model = $rows->find([$key])?->first();

        if ($model === null) {
            throw new NotFoundHttpException(__('brume::grid.cell.not_found'));
        }

        match ($column->editor()->type()) {
            'switchGroup' => array_walk($value, fn (mixed $on, string $attribute) => $model->setAttribute($attribute, $this->toBoolean($on))),
            'switch' => $model->setAttribute($column->key(), $this->toBoolean($value)),
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
     * @throws ValidationException with every message under the column key
     */
    protected function validValue(Column $column, mixed $value): mixed
    {
        $key = $column->key();
        $validator = Validator::make([$key => $value], $this->rules($column), [], $this->attributeNames($column));

        if ($validator->fails()) {
            throw ValidationException::withMessages([$key => array_values(array_unique($validator->errors()->all()))]);
        }

        return $value;
    }

    /**
     * The column's rules with those its editor implies.
     *
     * @return array<string, list<mixed>>
     */
    protected function rules(Column $column): array
    {
        $key = $column->key();
        $editor = $column->editor()->toSchema();
        $declared = $column->editRules();
        $declared = is_string($declared) ? array_values(array_filter(explode('|', $declared), fn (string $rule) => $rule !== '')) : array_values($declared);
        $options = array_column($editor['options'] ?? [], 'value');

        return match ($editor['type']) {
            'select', 'radio' => [$key => [...$declared, Rule::in($options)]],
            'checkbox' => [$key => ['array', 'list', ...$declared], "{$key}.*" => [Rule::in($options)]],
            'switch' => [$key => [...$declared, 'boolean']],
            'switchGroup' => [$key => ['required', 'array:'.implode(',', array_keys($column->switchKeys()))]]
                + array_fill_keys(array_map(fn (string $attribute) => "{$key}.{$attribute}", array_keys($column->switchKeys())), ['boolean']),
            'number' => [$key => [
                ...$declared, 'numeric',
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
