<?php

namespace Brume\Grid;

use Brume\Contracts\Renderable;
use Brume\Facades\Brume;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class Grid implements Renderable, Responsable
{
    protected string $title = '';

    protected ?string $description = null;

    protected int $perPage = 20;

    /** @var list<Column> */
    protected array $columns = [];

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

    public function perPage(int $n): static
    {
        $this->perPage = max(1, $n);

        return $this;
    }

    /**
     * Declare a column and return it for configuration; call $grid->column() again for the next one.
     */
    public function column(string $key, ?string $label = null): Column
    {
        return $this->columns[] = new Column($key, $label ?? Column::defaultLabel($key));
    }

    public function toSchema(Request $request): array
    {
        $paginator = $this->paginate($this->requestedPage($request));

        // Past the end: answer with the last page and report it as the current one.
        if ($paginator->currentPage() > $paginator->lastPage()) {
            $paginator = $this->paginate(max(1, $paginator->lastPage()));
        }

        return [
            'type' => 'list',
            'title' => $this->title,
        ]
            + ($this->description !== null ? ['description' => $this->description] : [])
            + [
                'columns' => array_map(fn (Column $column) => $column->toSchema(), $this->columns),
                'rows' => $paginator->getCollection()->map(fn (Model $row) => $this->row($row))->all(),
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'lastPage' => max(1, $paginator->lastPage()),
                ],
            ];
    }

    public function toResponse($request)
    {
        return Brume::render($this)->toResponse($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Model $model): array
    {
        $row = ['id' => $model->getKey()];

        foreach ($this->columns as $column) {
            $row[$column->key()] = $column->value($model);
        }

        return $row;
    }

    protected function paginate(int $page): LengthAwarePaginator
    {
        $query = clone $this->query;

        // Without an ORDER BY, databases like MySQL may return rows in any order, so pages could
        // repeat or skip rows. Ascending primary key unless the query brings its own order.
        if (empty($query->getQuery()->orders) && empty($query->getQuery()->unionOrders)) {
            $query->orderBy($query->getModel()->getQualifiedKeyName());
        }

        return $query->paginate($this->perPage, ['*'], 'page', $page);
    }

    /**
     * Anything that is not a positive integer becomes page 1.
     */
    protected function requestedPage(Request $request): int
    {
        $page = $request->query('page');

        if (! is_string($page) || ! preg_match('/^[1-9][0-9]*$/', $page)) {
            return 1;
        }

        // Guard against integer overflow in the OFFSET; anything this big clamps to the last page anyway.
        return min((int) substr($page, 0, 9), 999_999_999);
    }
}
