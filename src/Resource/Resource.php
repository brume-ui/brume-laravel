<?php

namespace Brume\Resource;

use Brume\Facades\Brume;
use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Grid\Grid;
use Brume\Grid\RowLookup;
use Brume\Http\ErrorResponse;
use Brume\Pages\ErrorPage;
use Brume\Uploads\UploadController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

abstract class Resource extends Controller
{
    protected string $model;

    protected string $name = '';

    protected bool $deletable = true;

    protected function query(): Builder
    {
        return $this->model::query();
    }

    abstract protected function grid(Grid $grid): void;

    abstract protected function form(Form $form): void;

    protected function quickCreate(Form $form): void {}

    final public function index(Request $request): Grid
    {
        $grid = Grid::make($this->query())->title($this->name);
        $this->grid($grid);
        $form = $this->buildForm($request);
        $grid->resource(['name' => $this->name, 'createUrl' => $this->url($request, 'create'), 'editUrl' => $this->url($request, 'edit', '__BRUME_ID__'), 'presentation' => $form->resolvedPresentation()]);
        $grid->editUrl(fn (Model $record) => $this->url($request, 'edit', $record->getKey()));
        if ($this->deletable) {
            $grid->deletable()->deleteUsing(fn (Model $record) => $this->buildForm($request, $record));
        }

        return $grid;
    }

    final public function create(Request $request)
    {
        return $this->buildForm($request)->toResponse($request);
    }

    final public function edit(Request $request)
    {
        return ($record = $this->record($request)) === null ? $this->notFound($request) : $this->buildForm($request, $record)->toResponse($request);
    }

    final public function store(Request $request)
    {
        return $this->persist($request, null);
    }

    final public function update(Request $request)
    {
        return ($record = $this->record($request)) === null ? $this->notFound($request) : $this->persist($request, $record);
    }

    protected function persist(Request $request, ?Model $record)
    {
        $form = $this->buildForm($request, $record);
        try {
            if ($request->route()->getAction('brume_resource_upload')) {
                $form->initialize();

                return app(UploadController::class)->store($form, $request);
            }

            return response()->json(app(FormSaver::class)->save($form, $record, $request));
        } catch (ValidationException $e) {
            return ErrorResponse::make($e->getMessage(), $e->status, $e->errors());
        }
    }

    protected function buildForm(Request $request, ?Model $record = null): Form
    {
        $form = Form::make($this->query())->id($request->route()->getAction(ResourceRouteRegistrar::ACTION_KEY))->name($this->name)->forRecord($record)
            ->submitTo($record === null ? 'post' : 'put', $this->url($request, $record === null ? 'store' : 'update', $record?->getKey()))
            ->uploadUrl($this->url($request, $record === null ? 'upload' : 'record-upload', $record?->getKey()))
            ->listUrl($this->url($request, 'index'))
            ->urls(fn (Model $saved) => ['list' => $this->url($request, 'index'), 'edit' => $this->url($request, 'edit', $saved->getKey()), 'create' => $this->url($request, 'create')]);
        $this->form($form);

        return $form;
    }

    protected function url(Request $request, string $action, int|string|null $id = null): string
    {
        $url = route($request->route()->getAction(ResourceRouteRegistrar::ROUTE_NAMES)[$action], ['_brume_record' => $id, ...$request->route()->parameters()], false);

        return str_replace('__BRUME_ID__', '{id}', $url);
    }

    protected function record(Request $request): ?Model
    {
        ResourceRouteRegistrar::takeArgument($request->route(), $request);
        $lookup = new RowLookup($this->query());
        $id = $lookup->normalizeId($request->attributes->get(ResourceRouteRegistrar::RECORD));

        return $id === null ? null : $lookup->find([$id])?->first();
    }

    protected function notFound(Request $request)
    {
        if ($request->hasHeader('X-Brume-Form') || $request->expectsJson()) {
            $response = ErrorResponse::make(__('brume::error.not_found.message'), 404);
        } else {
            $response = Brume::render(ErrorPage::notFound())->toResponse($request)->setStatusCode(404);
        }
        $response->setVary('X-Brume-Form', false);

        return $response;
    }
}
