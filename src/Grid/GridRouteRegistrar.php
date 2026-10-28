<?php

namespace Brume\Grid;

use Brume\Http\Controllers\GridEndpointController;
use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Route::brumeGrid('orders', OrderController::class): the list page plus the `_brume` endpoints
 * its actions, inline editing, export and remote filter options talk to.
 */
final class GridRouteRegistrar
{
    public function __construct(protected Router $router) {}

    /**
     * @param  string|array{0: class-string, 1: string}|Closure  $action  must return a Grid
     * @return Route the GET page route, so ->name() and ->middleware() can follow
     */
    public function register(string $uri, string|array|Closure $action): Route
    {
        $uri = trim($uri, '/');
        $base = ($uri === '' ? '' : $uri.'/').'_brume';

        $endpoints = [
            ['post', 'actions/{action}', 'action'],
            ['patch', 'cells/{id}', 'cell'],
            ['get', 'export', 'export'],
            ['get', 'options/{source}', 'options'],
        ];

        foreach ($endpoints as [$method, $path, $handler]) {
            $this->router->{$method}("{$base}/{$path}", [GridEndpointController::class, $handler])
                ->defaults('_brume_grid_action', $action);
        }

        return $this->router->get($uri, $action)->defaults('_brume_grid', true);
    }
}
