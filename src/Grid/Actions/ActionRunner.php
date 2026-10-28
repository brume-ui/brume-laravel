<?php

namespace Brume\Grid\Actions;

use Brume\Grid\Grid;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
 * list's search, filters or paging), so an id the list could never show is refused with 404, and a
 * row the action's visible() refuses with 403, before anything runs. The action runs in a database
 * transaction on the model's connection.
 */
class ActionRunner
{
    public function run(Grid $grid, Request $request, string $key): JsonResponse
    {
        try {
            ['target' => $target, 'ids' => $ids] = $this->validated($request);

            $action = $grid->findAction($target, $key);

            if ($action === null) {
                return $this->error(__('brume::grid.endpoint_unavailable'), 404);
            }

            $query = $grid->actionQuery();
            $models = $ids === [] ? new EloquentCollection : (clone $query)->whereKey($ids)->get();

            if ($models->count() < count($ids)) {
                return $this->error(__('brume::grid.action.not_found'), 404);
            }

            if ($models->contains(fn (Model $row) => ! $action->isVisibleFor($row))) {
                return $this->error(__('brume::grid.action.forbidden'), 403);
            }

            $feedback = $query->getConnection()->transaction(fn () => $action->execute($models, $request));
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors());
        }

        $feedback ??= Feedback::toast('success', __('brume::grid.action.done', ['label' => $action->label()]))->refresh();

        // An object even when empty: a PHP [] would encode as a JSON list.
        return new JsonResponse(['feedback' => (object) $feedback->toArray()]);
    }

    /**
     * The ActionRequest, with each id once: a row action takes one id, a batch action at least
     * one, a tool none.
     *
     * @return array{target: 'row'|'batch'|'tool', ids: list<int|string>}
     *
     * @throws ValidationException
     */
    protected function validated(Request $request): array
    {
        $target = $request->input('target');

        $data = Validator::make($request->all(), [
            'target' => ['required', 'string', 'in:row,batch,tool'],
            'ids' => [
                'present', 'array', 'list',
                ...match ($target) {
                    'row' => ['size:1'],
                    'batch' => ['min:1'],
                    'tool' => ['max:0'],
                    default => [],
                },
            ],
            'ids.*' => [function (string $attribute, mixed $value, Closure $fail) {
                if (! is_int($value) && ! (is_string($value) && $value !== '')) {
                    $fail(__('brume::grid.action.invalid_id'));
                }
            }],
        ])->validate();

        return ['target' => $data['target'], 'ids' => array_values(array_unique($data['ids']))];
    }

    /**
     * An ErrorResponse: { message, errors? }.
     *
     * @param  array<string, list<string>>|null  $errors
     */
    protected function error(string $message, int $status, ?array $errors = null): JsonResponse
    {
        return new JsonResponse(['message' => $message] + ($errors !== null ? ['errors' => $errors] : []), $status);
    }
}
