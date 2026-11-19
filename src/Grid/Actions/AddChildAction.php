<?php

namespace Brume\Grid\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Grid::treeView()'s 新增子項 on each node: opens the resource's create form with
 * ?_brume_parent={id}, which Resource::create() turns into the parent field's default.
 */
class AddChildAction extends Action
{
    public const KEY = 'add-child';

    public const PARAMETER = '_brume_parent';

    protected string $createUrl = '';

    public static function to(string $createUrl): static
    {
        $action = static::make(self::KEY, __('brume::grid.tree.add_child'))->icon('plus');
        $action->createUrl = $createUrl;

        return $action;
    }

    /**
     * @param  Collection<int, Model>  $models
     */
    protected function run(Collection $models, Request $request): ?Feedback
    {
        $separator = str_contains($this->createUrl, '?') ? '&' : '?';

        return Feedback::make()->redirect($this->createUrl.$separator.self::PARAMETER.'='.rawurlencode((string) $models->first()->getKey()));
    }
}
