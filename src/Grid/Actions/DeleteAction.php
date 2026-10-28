<?php

namespace Brume\Grid\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Grid::deletable(): deletes each row with $model->delete(), so model events fire and soft deletes
 * are kept.
 */
class DeleteAction extends Action
{
    public const KEY = 'delete';

    public static function forRow(): static
    {
        return static::make(self::KEY, __('brume::grid.delete.label'))->icon('trash-2')->danger()->confirm(
            __('brume::grid.delete.confirm_row'),
            __('brume::grid.delete.confirm_row_description'),
            __('brume::grid.delete.label'),
        );
    }

    /**
     * {count} stays in the texts: the page fills in the number of selected rows.
     */
    public static function forBatch(): static
    {
        return static::make(self::KEY, __('brume::grid.delete.label'))->icon('trash-2')->danger()->confirm(
            __('brume::grid.delete.confirm_batch'),
            confirmLabel: __('brume::grid.delete.confirm_batch_label'),
        );
    }

    /**
     * @param  Collection<int, Model>  $models
     */
    protected function run(Collection $models, Request $request): ?Feedback
    {
        // A deleting listener that returns false keeps its row, which is then not counted.
        $deleted = $models->filter(fn (Model $model) => $model->delete() !== false)->count();

        return Feedback::toast('success', trans_choice('brume::grid.delete.done', $deleted, ['count' => $deleted]))->refresh();
    }
}
