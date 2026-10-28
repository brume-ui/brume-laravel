<?php

namespace Brume\Grid\Actions;

use Brume\Grid\Grid;
use Brume\Grid\RowLookup;
use Brume\Http\ErrorResponse;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Answers endpoints.action: an ActionRequest { target: row|batch|tool, ids } for the action keyed
 * $key, with an ActionResponse { feedback } or an ErrorResponse.
 *
 * The rows come from the grid's own query (the developer's conditions and global scopes, not the
 * list's search, filters or paging; see Grid::rows()), so an id the list could never show is refused
 * with 404, and a row the action's visible() refuses with 403, before anything runs. A batch takes
 * at most the grid's largest page of ids. The action runs in a database transaction on the model's
 * connection.
 */
class ActionRunner
{
    public function run(Grid $grid, Request $request, string $key): JsonResponse
    {
        try {
            $rows = $grid->rows();
            ['target' => $target, 'ids' => $ids] = $this->validated($request, $rows, $grid->maxBatchSize());

            $action = $grid->findAction($target, $key);

            if ($action === null) {
                return ErrorResponse::make(__('brume::grid.endpoint_unavailable'), 404);
            }

            $models = $rows->find($ids);

            if ($models === null) {
                return ErrorResponse::make(__('brume::grid.action.not_found'), 404);
            }

            if ($models->contains(fn (Model $row) => ! $action->isVisibleFor($row))) {
                return ErrorResponse::make(__('brume::grid.action.forbidden'), 403);
            }

            $feedback = $rows->query()->getConnection()->transaction(fn () => $action->execute($models, $request));
        } catch (ValidationException $e) {
            return ErrorResponse::make($e->getMessage(), $e->status, $e->errors());
        }

        $feedback ??= Feedback::toast('success', __('brume::grid.action.done', ['label' => $action->label()]))->refresh();

        // An object even when empty: a PHP [] would encode as a JSON list.
        return new JsonResponse(['feedback' => (object) $feedback->toArray()]);
    }

    /**
     * The ActionRequest, with each id normalized and once (see RowLookup::normalizeId(): "01" is 1
     * on an integer key): a row action takes one id, a batch action one to $max, a tool none.
     *
     * @return array{target: 'row'|'batch'|'tool', ids: list<int|string>}
     *
     * @throws ValidationException
     */
    protected function validated(Request $request, RowLookup $rows, int $max): array
    {
        $target = $request->input('target');

        $data = Validator::make($request->all(), [
            'target' => ['required', 'string', 'in:row,batch,tool'],
            'ids' => [
                'present', 'array', 'list',
                ...match ($target) {
                    'row' => ['size:1'],
                    'batch' => ['min:1', 'max:'.$max],
                    'tool' => ['max:0'],
                    default => [],
                },
            ],
            'ids.*' => [function (string $attribute, mixed $value, Closure $fail) use ($rows) {
                if ($rows->normalizeId($value) === null) {
                    $fail(__('brume::grid.invalid_id'));
                }
            }],
        ], $target === 'batch' ? ['ids.max' => __('brume::grid.action.too_many', ['max' => $max])] : [])->validate();

        $ids = array_map(fn (mixed $id) => $rows->normalizeId($id), $data['ids']);

        return ['target' => $data['target'], 'ids' => array_values(array_unique($ids))];
    }
}
