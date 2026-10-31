<?php

namespace Brume\Grid\Editing;

use Brume\Form\JsonValues;
use Brume\Grid\Column;
use Brume\Grid\Grid;
use Brume\Grid\RowLookup;
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
        $input = $column->editor()->type() === 'custom' ? JsonValues::requestValue($request, 'value') : $request->input('value');
        $value = $this->validValue($column, $input, $rows->query()->getModel());

        $model = $rows->find([$key])?->first();

        if ($model === null) {
            throw new NotFoundHttpException(__('brume::grid.cell.not_found'));
        }

        // Refuse before writing, as the list does when it renders the row.
        $column->assertCheckboxCast($model);

        match (true) {
            $column->editor()->mappedField() !== null => $column->editor()->mappedField()->fill($model, $value),
            $column->editor()->type() === 'switchGroup' => array_walk($value, fn (mixed $on, string|int $attribute) => $model->setAttribute((string) $attribute, $on)),
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
        $editor = $column->editor();
        $value = $editor->prepare($value);
        $rules = $editor->rules($column, $model);
        $declared = $column->editRules();
        $declared = is_string($declared) ? array_filter(explode('|', $declared), fn ($rule) => $rule !== '') : $declared;
        $rules[$key] = [...$rules[$key], ...array_values($declared)];
        $validator = Validator::make([$key => JsonValues::validationView($value)], $rules, [], $this->attributeNames($column));

        if ($validator->fails()) {
            throw ValidationException::withMessages($editor->type() === 'custom' ? $validator->errors()->toArray() : [$key => array_values(array_unique($validator->errors()->all()))]);
        }

        return $column->editor()->normalize($value);
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
}
