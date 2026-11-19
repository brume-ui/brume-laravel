<?php

namespace Brume\Http\Controllers;

use Brume\Form\FormSaver;
use Brume\Grid\Actions\ActionRunner;
use Brume\Grid\Editing\CellUpdater;
use Brume\Grid\Export\XlsxExporter;
use Brume\Grid\Grid;
use Brume\Grid\GridRouteRegistrar;
use Brume\Grid\Ordering\ReorderConflict;
use Brume\Grid\Ordering\Reorderer;
use Brume\Http\ErrorResponse;
use Brume\Http\Middleware\HandleGridEndpoint;
use Brume\Options\OptionContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answers the `_brume` endpoints of a Route::brumeGrid page with the Grid the page action returned
 * (called from Grid::toResponse()). A grid that declares nothing for an endpoint gets a JSON 404.
 */
class GridEndpointController
{
    public function handle(Grid $grid, Request $request): Response
    {
        $request->attributes->set(HandleGridEndpoint::HANDLED, true);
        HandleGridEndpoint::takeArgument($request->route(), $request);
        $argument = $request->attributes->get(HandleGridEndpoint::ARGUMENT);

        return match ($request->route()->getAction(GridRouteRegistrar::ACTION_KEY)) {
            'quick-create' => $this->quickCreate($grid, $request),
            'action' => $this->action($grid, $request, (string) $argument),
            'cell' => $this->cell($grid, $request, (string) $argument),
            'export' => $this->export($grid, $request),
            'options' => $this->options($grid, $request, (string) $argument),
            'reorder-items' => $this->reorderItems($grid),
            'reorder' => $this->reorder($grid, $request),
        };
    }

    public function quickCreate(Grid $grid, Request $request): JsonResponse
    {
        $form = $grid->quickForm();
        if ($form === null) {
            return $this->unavailable();
        }
        try {
            return new JsonResponse(app(FormSaver::class)->save($form, null, $request));
        } catch (ValidationException $e) {
            return ErrorResponse::make($e->getMessage(), $e->status, $e->errors());
        }
    }

    /**
     * The ActionResponse of the row, batch or tool action keyed $action.
     */
    public function action(Grid $grid, Request $request, string $action): JsonResponse
    {
        return app(ActionRunner::class)->run($grid, $request, $action);
    }

    /**
     * The CellUpdateResponse of the inline edit of row $id.
     */
    public function cell(Grid $grid, Request $request, string $id): JsonResponse
    {
        if (! $grid->editsInline()) {
            return $this->unavailable();
        }

        try {
            return new JsonResponse(app(CellUpdater::class)->update($grid, $request, $id));
        } catch (ValidationException $e) {
            return ErrorResponse::make($e->getMessage(), $e->status, $e->errors());
        } catch (NotFoundHttpException $e) {
            return ErrorResponse::make($e->getMessage(), 404);
        }
    }

    /**
     * The .xlsx download of all, the current page's or the selected rows (XlsxExporter).
     */
    public function export(Grid $grid, Request $request): Response
    {
        if (! $grid->isExportable()) {
            return $this->unavailable();
        }

        try {
            return app(XlsxExporter::class)->stream($grid, $request);
        } catch (ValidationException $e) {
            return ErrorResponse::make($e->getMessage(), $e->status, $e->errors());
        }
    }

    /**
     * The OptionsResponse of the remote filter keyed $source.
     */
    public function options(Grid $grid, Request $request, string $source): JsonResponse
    {
        if (($declared = $grid->filters()->optionSource($source)) !== null) {
            return new JsonResponse($declared->response(new OptionContext(null), $request->query(), true));
        }
        $options = $grid->filters()->remoteOptions($source);

        return $options !== null ? new JsonResponse($options->respond($request)) : $this->unavailable();
    }

    /**
     * The ReorderItemsResponse of an orderable() grid: every row of its query, in order.
     */
    public function reorderItems(Grid $grid): JsonResponse
    {
        if ($grid->ordering() === null) {
            return $this->unavailable();
        }

        try {
            return new JsonResponse(app(Reorderer::class)->items($grid));
        } catch (ValidationException $e) {
            return ErrorResponse::make($e->getMessage(), $e->status, $e->errors());
        }
    }

    /**
     * A ReorderMoveRequest { id, before?, after? } answered with a ReorderResponse { undo? }, or a
     * ReorderListRequest { ids } answered with {}. A row that left the grid's query, or a list
     * that is not exactly its rows, is a 409.
     */
    public function reorder(Grid $grid, Request $request): JsonResponse
    {
        if ($grid->ordering() === null) {
            return $this->unavailable();
        }

        $reorderer = app(Reorderer::class);

        try {
            if ($request->exists('ids')) {
                $ids = $request->input('ids');

                if (! is_array($ids) || ! array_is_list($ids)) {
                    throw ValidationException::withMessages(['ids' => __('brume::grid.reorder.ids')]);
                }

                $reorderer->saveList($grid, $ids);

                return new JsonResponse((object) []);
            }

            return new JsonResponse((object) $reorderer->move($grid, $request->all()));
        } catch (ValidationException $e) {
            return ErrorResponse::make($e->getMessage(), $e->status, $e->errors());
        } catch (ReorderConflict $e) {
            return ErrorResponse::make($e->getMessage(), 409);
        }
    }

    /**
     * An ErrorResponse: { message }.
     */
    protected function unavailable(): JsonResponse
    {
        return ErrorResponse::make(__('brume::grid.endpoint_unavailable'), 404);
    }
}
