<?php

namespace Brume\Http\Controllers;

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

    public function action(Grid $grid, Request $request, string $action): JsonResponse
    {
        return $this->unavailable();
    }

    public function cell(Grid $grid, Request $request, string $id): JsonResponse
    {
        return $this->unavailable();
    }

    public function export(Grid $grid, Request $request): JsonResponse
    {
        return $this->unavailable();
    }

    public function options(Grid $grid, Request $request, string $source): JsonResponse
    {
        return $this->unavailable();
    }

    /**
     * An ErrorResponse: { message }.
     */
    protected function unavailable(): JsonResponse
    {
        return new JsonResponse(['message' => __('brume::grid.endpoint_unavailable')], 404);
    }
}
