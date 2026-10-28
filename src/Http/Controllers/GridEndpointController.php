<?php

namespace Brume\Http\Controllers;

use Brume\Grid\Actions\ActionRunner;
use Brume\Grid\Grid;
use Brume\Grid\GridRouteRegistrar;
use Brume\Http\Middleware\HandleGridEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Answers the `_brume` endpoints of a Route::brumeGrid page with the Grid the page action returned
 * (called from Grid::toResponse()). A grid that declares nothing for an endpoint gets a JSON 404.
 */
class GridEndpointController
{
    public function handle(Grid $grid, Request $request): JsonResponse
    {
        $request->attributes->set(HandleGridEndpoint::HANDLED, true);
        $argument = $request->attributes->get(HandleGridEndpoint::ARGUMENT);

        return match ($request->route()->getAction(GridRouteRegistrar::ACTION_KEY)) {
            'action' => $this->action($grid, $request, (string) $argument),
            'cell' => $this->cell($grid, $request, (string) $argument),
            'export' => $this->export($grid, $request),
            'options' => $this->options($grid, $request, (string) $argument),
        };
    }

    /**
     * The ActionResponse of the row, batch or tool action keyed $action.
     */
    public function action(Grid $grid, Request $request, string $action): JsonResponse
    {
        return app(ActionRunner::class)->run($grid, $request, $action);
    }

    public function cell(Grid $grid, Request $request, string $id): JsonResponse
    {
        return $this->unavailable();
    }

    public function export(Grid $grid, Request $request): JsonResponse
    {
        return $this->unavailable();
    }

    /**
     * The OptionsResponse of the remote filter keyed $source.
     */
    public function options(Grid $grid, Request $request, string $source): JsonResponse
    {
        $options = $grid->filters()->remoteOptions($source);

        return $options !== null ? new JsonResponse($options->respond($request)) : $this->unavailable();
    }

    /**
     * An ErrorResponse: { message }.
     */
    protected function unavailable(): JsonResponse
    {
        return new JsonResponse(['message' => __('brume::grid.endpoint_unavailable')], 404);
    }
}
