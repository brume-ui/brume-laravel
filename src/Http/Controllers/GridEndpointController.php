<?php

namespace Brume\Http\Controllers;

use Brume\Grid\Grid;
use Brume\Grid\GridResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The `_brume` endpoints of a Route::brumeGrid page. Each one rebuilds the page's Grid through
 * GridResolver; a grid that declares nothing for an endpoint answers with a JSON 404.
 */
class GridEndpointController
{
    public function action(Request $request): JsonResponse
    {
        $this->grid($request);

        return $this->unavailable();
    }

    public function cell(Request $request): JsonResponse
    {
        $this->grid($request);

        return $this->unavailable();
    }

    public function export(Request $request): JsonResponse
    {
        $this->grid($request);

        return $this->unavailable();
    }

    public function options(Request $request): JsonResponse
    {
        $this->grid($request);

        return $this->unavailable();
    }

    protected function grid(Request $request): Grid
    {
        return GridResolver::resolve($request->route()->defaults['_brume_grid_action'], $request);
    }

    /**
     * An ErrorResponse: { message }.
     */
    protected function unavailable(): JsonResponse
    {
        return new JsonResponse(['message' => __('brume::grid.endpoint_unavailable')], 404);
    }
}
