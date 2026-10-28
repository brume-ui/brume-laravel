<?php

namespace Brume\Grid;

use Brume\Contracts\Renderable;
use Brume\Facades\Brume;
use Brume\Grid\Actions\Action;
use Brume\Grid\Actions\DeleteAction;
use Brume\Grid\Filters\Filters;
use Brume\Grid\Query\Like;
use Brume\Http\Controllers\GridEndpointController;
use Closure;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class Grid implements Renderable, Responsable
{
    public const DEFAULT_PER_PAGE_OPTIONS = [10, 20, 50, 100];

    protected string $title = '';

    protected ?string $description = null;

    protected ?string $id = null;

    protected int $perPage = 20;

    /** @var list<int> */
    protected array $perPageOptions = self::DEFAULT_PER_PAGE_OPTIONS;

    /** @var list<Column> */
    protected array $columns = [];

    /** @var list<string|Closure> */
    protected array $quickSearch = [];

    protected ?string $quickSearchPlaceholder = null;

    /** @var array{key: string, direction: 'asc'|'desc'}|null */
    protected ?array $defaultSort = null;

    /** @var array<string, mixed>|null */
    protected ?array $empty = null;

    protected ?Filters $filters = null;

    /** @var array{row: list<Action>, batch: list<Action>, tool: list<Action>} */
    protected array $actions = ['row' => [], 'batch' => [], 'tool' => []];

    protected bool $deletable = false;

    protected ?Closure $editUrl = null;

    final public function __construct(protected Builder $query) {}

    /**
     * @param  class-string<Model>|Builder  $modelOrQuery
     */
    public static function make(string|Builder $modelOrQuery): static
    {
        return new static(is_string($modelOrQuery) ? $modelOrQuery::query() : $modelOrQuery);
    }

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function description(string $text): static
    {
        $this->description = $text;

        return $this;
    }

    /**
     * The key the frontend remembers per-grid choices under (hidden columns). Defaults to the
     * current route name, or the path when the route has none.
     */
    public function id(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function perPage(int $n): static
    {
        $this->perPage = max(1, $n);

        return $this;
    }

    /**
     * The 每頁 {n} 筆 choices; perPage() is always added to them.
     *
     * @param  list<int>  $options
     */
    public function perPageOptions(array $options): static
    {
        if ($options === [] || array_filter($options, fn ($n) => ! is_int($n) || $n < 1) !== []) {
            throw new InvalidArgumentException('perPageOptions() 需要一組正整數，例如 [10, 20, 50, 100]。');
        }

        $this->perPageOptions = array_values($options);

        return $this;
    }

    /**
     * Search these columns with ?q=: a case-insensitive "contains" on each, joined with OR.
     * 'relation.column' searches through the relation (whereHas); a closure receives
     * (Builder $query, string $term) and adds its own condition.
     */
    public function quickSearch(string|Closure ...$columns): static
    {
        if ($columns === []) {
            throw new InvalidArgumentException('quickSearch() 至少要指定一個欄位，例如 quickSearch(\'name\', \'email\')。');
        }

        $this->quickSearch = array_values($columns);

        return $this;
    }

    public function quickSearchPlaceholder(string $text): static
    {
        $this->quickSearchPlaceholder = $text;

        return $this;
    }

    /**
     * The order when the URL asks for none. It is applied on the server only and not reported in
     * page.query.sort, so the header's sort cycle can still get back to "unsorted".
     */
    public function defaultSort(string $key, string $direction = 'asc'): static
    {
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException("defaultSort() 的方向只能是 asc 或 desc，目前是「{$direction}」。");
        }

        $this->defaultSort = ['key' => $key, 'direction' => $direction];

        return $this;
    }

    /**
     * What the list says when it has no rows and no search or filter applies, with an optional link.
     */
    public function emptyState(string $title, string $description, ?string $actionLabel = null, ?string $actionUrl = null): static
    {
        if (($actionLabel === null) !== ($actionUrl === null)) {
            throw new InvalidArgumentException('emptyState() 的按鈕需要同時提供文字和網址。');
        }

        $this->empty = ['title' => $title, 'description' => $description]
            + ($actionLabel !== null ? ['action' => ['label' => $actionLabel, 'url' => $actionUrl]] : []);

        return $this;
    }

    /**
     * Declare a column and return it for configuration; call $grid->column() again for the next one.
     */
    public function column(string $key, ?string $label = null): Column
    {
        return $this->columns[] = (new Column($key, $label ?? Column::defaultLabel($key)))->withFilters($this->filters());
    }

    /**
     * The filter panel, hidden filters, selectors and scopes (column-header filters come from
     * Column::filter()). Each filter reads filter[key] from the URL.
     */
    public function filters(): Filters
    {
        return $this->filters ??= new Filters;
    }

    /**
     * Actions in each row's ⋯ menu (and its right-click menu); exposed() ones also show as buttons.
     */
    public function rowActions(Action ...$actions): static
    {
        $this->addActions('row', $actions);

        $exposed = array_filter($this->actions['row'], fn (Action $action) => $action->isExposed());

        if (count($exposed) > 2 && app()->environment('local')) {
            throw new LogicException(sprintf(
                '列表「%s」有 %d 個 exposed() 的列操作（%s），最多 2 個，其他的請放在「⋯」選單裡。',
                $this->title, count($exposed), implode('、', array_map(fn (Action $action) => $action->key(), $exposed)),
            ));
        }

        return $this;
    }

    /**
     * Actions on the selected rows, in the bar shown while rows are selected.
     */
    public function batchActions(Action ...$actions): static
    {
        $this->addActions('batch', $actions);

        return $this;
    }

    /**
     * Actions in the toolbar, run on no particular row.
     */
    public function tools(Action ...$actions): static
    {
        $this->addActions('tool', $actions);

        return $this;
    }

    /**
     * A 刪除 row and batch action (key 'delete'), listed after the declared ones. Each row is
     * deleted with $model->delete(), so model events fire and soft deletes are kept.
     */
    public function deletable(bool $on = true): static
    {
        if ($on) {
            foreach (['row', 'batch'] as $target) {
                $this->assertNewKey($target, DeleteAction::KEY);
            }
        }

        $this->deletable = $on;

        return $this;
    }

    /**
     * The row's edit page, fn (Model $row): ?string, sent in _meta.editUrl: the primary column
     * links to it and the row offers 編輯.
     */
    public function editUrl(Closure $fn): static
    {
        $this->editUrl = $fn;

        return $this;
    }

    /**
     * The action keyed $key among those of $target, deletable()'s included.
     *
     * @internal
     *
     * @param  'row'|'batch'|'tool'  $target
     */
    public function findAction(string $target, string $key): ?Action
    {
        foreach ($this->actionsOf($target) as $action) {
            if ($action->key() === $key) {
                return $action;
            }
        }

        return null;
    }

    /**
     * The rows actions and inline edits may touch: the grid's query (its conditions and global
     * scopes) without the list's search, filters, sort or paging.
     *
     * @internal
     */
    public function rows(): RowLookup
    {
        return new RowLookup(clone $this->query);
    }

    /**
     * The most rows one batch action takes: the largest page the list can show.
     *
     * @internal
     */
    public function maxBatchSize(): int
    {
        return max($this->perPageChoices());
    }

    public function toSchema(Request $request): array
    {
        $state = GridQuery::fromRequest($request, $this);
        $endpoints = $this->endpoints($request);
        // Without the endpoint nothing could run them, so the actions are left out.
        $actions = $endpoints !== null ? ['row' => $this->actionsOf('row'), 'batch' => $this->actionsOf('batch'), 'tool' => $this->actionsOf('tool')]
            : ['row' => [], 'batch' => [], 'tool' => []];

        $paginator = $this->paginate($state, $state->page);

        // Past the end: answer with the last page and report it as the current one.
        if ($paginator->currentPage() > $paginator->lastPage()) {
            $paginator = $this->paginate($state, max(1, $paginator->lastPage()));
        }

        return [
            'type' => 'list',
            'title' => $this->title,
        ]
            + ($this->description !== null ? ['description' => $this->description] : [])
            + [
                'gridId' => $this->id ?? $request->route()?->getName() ?? $request->path(),
                'columns' => array_map(fn (Column $column) => $column->toSchema(), $this->columns),
                'rows' => $paginator->getCollection()->map(fn (Model $row) => $this->row($row, $actions['row']))->all(),
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'lastPage' => max(1, $paginator->lastPage()),
                ],
                'query' => $state->toArray(),
                'perPageOptions' => $this->perPageChoices(),
            ]
            + ($this->searchable() ? ['quickSearch' => $this->quickSearchSchema()] : [])
            + $this->filters()->toSchema()
            + [
                'appliedFilters' => $this->appliedFilters($state),
                'actions' => [
                    'row' => array_map(fn (Action $action) => $action->toSchema(), $actions['row']),
                    'batch' => array_map(fn (Action $action) => $action->toSchema(), $actions['batch']),
                    'tools' => array_map(fn (Action $action) => $action->toSchema(), $actions['tool']),
                ],
            ]
            + ($endpoints !== null ? ['endpoints' => $endpoints] : [])
            + ($this->empty !== null ? ['empty' => $this->empty] : []);
    }

    /**
     * The list page, or, on a Route::brumeGrid `_brume` endpoint (which runs the page action too),
     * that endpoint's answer.
     */
    public function toResponse($request)
    {
        if ($this->endpointRequest($request)) {
            return app(GridEndpointController::class)->handle($this, $request);
        }

        return Brume::render($this)->toResponse($request);
    }

    /**
     * The grid's query with the search and sort of $state applied, not paginated.
     *
     * @internal
     */
    public function queryFor(GridQuery $state): Builder
    {
        $query = clone $this->query;

        if ($state->q !== null) {
            $this->applyQuickSearch($query, $state->q);
        }

        $this->filters()->apply($query, $state->filters, $state->scope);

        $this->applySort($query, $state->sort ?? $this->defaultSort);

        return $query;
    }

    /**
     * @internal
     */
    public function sortableColumn(string $key): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->key() === $key && $column->isSortable()) {
                return $column;
            }
        }

        return null;
    }

    /**
     * The per-page options with perPage() merged in, ascending.
     *
     * @internal
     *
     * @return list<int>
     */
    public function perPageChoices(): array
    {
        $options = array_unique([...$this->perPageOptions, $this->perPage]);
        sort($options);

        return $options;
    }

    /**
     * @internal
     */
    public function defaultPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * @internal
     */
    public function searchable(): bool
    {
        return $this->quickSearch !== [];
    }

    /**
     * Whether the grid declares something served by the `_brume` endpoints (actions, inline
     * editing, export, remote filter options), which only Route::brumeGrid registers.
     */
    protected function needsEndpoints(): bool
    {
        if ($this->filters()->hasRemote() || $this->deletable || array_merge(...array_values($this->actions)) !== []) {
            return true;
        }

        foreach ($this->columns as $column) {
            if ($column->isEditable()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `_brume` endpoint URLs, on a Route::brumeGrid page only. A grid that needs them on
     * another route fails in local and, elsewhere, logs a warning and leaves those features out.
     *
     * @return array<string, string>|null
     */
    protected function endpoints(Request $request): ?array
    {
        if ($this->routeRole($request) === 'page') {
            // getBaseUrl() keeps an app served from a subdirectory (/sub/admin/orders) working.
            $base = rtrim($request->getBaseUrl().'/'.trim($request->path(), '/'), '/').'/_brume';

            return [
                'action' => $base.'/actions/{action}',
                'cell' => $base.'/cells/{id}',
                'options' => $base.'/options/{source}',
            ];
        }

        if ($this->needsEndpoints()) {
            $message = sprintf(
                '列表「%s」宣告了 Action、行內編輯、匯出或遠端篩選，這些功能需要用 Route::brumeGrid 註冊路由，例如 Route::brumeGrid(\'orders\', OrderController::class)，而不是 Route::get。',
                $this->title,
            );

            if (app()->environment('local')) {
                throw new LogicException($message);
            }

            Log::warning($message.' 目前先省略這些功能。');
        }

        return null;
    }

    /**
     * 'page' on a Route::brumeGrid list page, the endpoint name on its `_brume` routes, else null.
     */
    protected function routeRole(Request $request): ?string
    {
        $route = $request->route();

        return $route instanceof Route ? $route->getAction(GridRouteRegistrar::ACTION_KEY) : null;
    }

    protected function endpointRequest(Request $request): bool
    {
        return ! in_array($this->routeRole($request), [null, 'page'], true);
    }

    /**
     * @return array<string, mixed>|object
     */
    protected function quickSearchSchema(): array|object
    {
        // An object even without a placeholder: a PHP [] would encode as a JSON list.
        return $this->quickSearchPlaceholder !== null ? ['placeholder' => $this->quickSearchPlaceholder] : (object) [];
    }

    /**
     * @return list<array{key: string, label: string, display: string}>
     */
    protected function appliedFilters(GridQuery $state): array
    {
        return [
            ...($state->q !== null ? [['key' => 'q', 'label' => __('brume::grid.search'), 'display' => $state->q]] : []),
            ...$this->filters()->applied($state->filters, $state->scope),
        ];
    }

    protected function applyQuickSearch(Builder $query, string $term): void
    {
        $query->where(function (Builder $group) use ($term) {
            foreach ($this->quickSearch as $column) {
                if ($column instanceof Closure) {
                    $group->orWhere(fn (Builder $nested) => $column($nested, $term));
                } elseif (str_contains($column, '.')) {
                    $group->orWhereHas(
                        Str::beforeLast($column, '.'),
                        fn (Builder $related) => Like::contains($related, $related->qualifyColumn(Str::afterLast($column, '.')), $term),
                    );
                } else {
                    Like::contains($group, $group->qualifyColumn($column), $term, boolean: 'or');
                }
            }
        });
    }

    /**
     * Without an ORDER BY, databases like MySQL may return rows in any order, so pages could repeat or
     * skip rows. A sort ends with the primary key so ties keep a stable order; with no sort, the
     * query's own order stands, or the ascending primary key when it has none.
     *
     * @param  array{key: string, direction: 'asc'|'desc'}|null  $sort
     */
    protected function applySort(Builder $query, ?array $sort): void
    {
        $keyName = $query->getModel()->getKeyName();

        if ($sort === null) {
            if (empty($query->getQuery()->orders) && empty($query->getQuery()->unionOrders)) {
                $query->orderBy($query->getModel()->getQualifiedKeyName());
            }

            return;
        }

        $query->reorder();

        if ($column = $this->sortableColumn($sort['key'])) {
            $column->applySort($query, $sort['direction']);
        } else {
            // A defaultSort() key without a sortable column: the developer's own column name.
            $query->orderBy($sort['key'], $sort['direction']);
        }

        if ($sort['key'] !== $keyName) {
            $query->orderBy($query->getModel()->getQualifiedKeyName());
        }
    }

    /**
     * @param  'row'|'batch'|'tool'  $target
     * @param  list<Action>  $actions
     */
    protected function addActions(string $target, array $actions): void
    {
        foreach ($actions as $action) {
            $this->assertNewKey($target, $action->key());
            $this->actions[$target][] = $action;
        }
    }

    /**
     * Action keys name the action on the endpoint, so they are unique per target.
     */
    protected function assertNewKey(string $target, string $key): void
    {
        $taken = array_map(fn (Action $action) => $action->key(), $this->actionsOf($target));

        if (in_array($key, $taken, true)) {
            throw new InvalidArgumentException($key === DeleteAction::KEY && $this->deletable
                ? '列表已經用 deletable() 加上 key 為 delete 的刪除操作，請換一個 key。'
                : "列表的 {$target} 操作已經有 key 為「{$key}」的 Action，同一類操作的 key 不能重複。");
        }
    }

    /**
     * @param  'row'|'batch'|'tool'  $target
     * @return list<Action>
     */
    protected function actionsOf(string $target): array
    {
        $delete = match (true) {
            ! $this->deletable => [],
            $target === 'row' => [DeleteAction::forRow()],
            $target === 'batch' => [DeleteAction::forBatch()],
            default => [],
        };

        return [...$this->actions[$target], ...$delete];
    }

    /**
     * The row's id, column values and, when there is any, its _meta: editUrl, the row actions it
     * allows (only when one declares visible()), and href, content and raw keyed by column.
     *
     * @param  list<Action>  $rowActions
     * @return array<string, mixed>
     */
    protected function row(Model $model, array $rowActions = []): array
    {
        $row = ['id' => $model->getKey()];
        $meta = ['href' => [], 'content' => [], 'raw' => []];

        foreach ($this->columns as $column) {
            $row[$column->key()] = $column->value($model);

            foreach ($column->rowMeta($model) as $entry => $value) {
                $meta[$entry][$column->key()] = $value;
            }
        }

        $meta = array_filter($meta);

        if ($this->editUrl !== null && ($editUrl = ($this->editUrl)($model)) !== null) {
            $meta['editUrl'] = $editUrl;
        }

        // Left out means every row action; an empty list (none allowed) is still sent.
        if (array_filter($rowActions, fn (Action $action) => $action->hasVisibility()) !== []) {
            $meta['actions'] = array_values(array_map(
                fn (Action $action) => $action->key(),
                array_filter($rowActions, fn (Action $action) => $action->isVisibleFor($model)),
            ));
        }

        return $row + ($meta !== [] ? ['_meta' => $meta] : []);
    }

    protected function paginate(GridQuery $state, int $page): LengthAwarePaginator
    {
        return $this->queryFor($state)->paginate($state->perPage, ['*'], 'page', $page);
    }
}
