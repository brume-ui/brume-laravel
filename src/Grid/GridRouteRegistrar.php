<?php

namespace Brume\Grid;

use Brume\Http\Middleware\HandleGridEndpoint;
use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Route::brumeGrid('orders', OrderController::class): the list page plus the `_brume` endpoints its
 * actions, inline editing, export and remote filter options talk to.
 *
 * Every endpoint runs the page's own action, so it goes through the same group, route and controller
 * middleware, route model binding (custom keys included) and route caching as the page. The Grid the
 * action returns then answers the endpoint instead of rendering the page (Grid::toResponse()).
 *
 * Route modifiers chained on the result (domain, middleware, can, where, scopeBindings, withTrashed, ...)
 * apply to all five routes; anything else (name, ...) goes to the page route. Called without
 * arguments, domain() and middleware() read the page route's value.
 *
 * @mixin Route
 */
final class GridRouteRegistrar
{
    /** Route action key: 'page' on the list page, the endpoint name on the others. */
    public const ACTION_KEY = 'brume_grid';

    /** Route parameter of each endpoint, prefixed so it never collides with a page parameter. */
    public const ENDPOINT_PARAMETERS = [
        'action' => '_brume_action',
        'cell' => '_brume_id',
        'options' => '_brume_source',
    ];

    /** Modifiers that must reach the endpoints too, or they would bypass the page's rules. */
    public const SHARED_MODIFIERS = [
        'domain', 'middleware', 'withoutMiddleware', 'can', 'scopeBindings', 'withoutScopedBindings', 'withTrashed',
        'missing', 'where', 'whereNumber', 'whereAlpha', 'whereAlphaNumeric', 'whereUuid', 'whereUlid', 'whereIn',
    ];

    protected Route $page;

    /** @var array<string, Route> */
    protected array $endpoints = [];

    public function __construct(protected Router $router) {}

    /**
     * @param  string|array{0: class-string, 1: string}|Closure  $action  must return a Grid
     */
    public function register(string $uri, string|array|Closure $action): static
    {
        $uri = trim($uri, '/');
        $base = ($uri === '' ? '' : $uri.'/').'_brume';

        $endpoints = [
            'action' => ['post', 'actions/{'.self::ENDPOINT_PARAMETERS['action'].'}'],
            'cell' => ['patch', 'cells/{'.self::ENDPOINT_PARAMETERS['cell'].'}'],
            'export' => ['get', 'export'],
            'options' => ['get', 'options/{'.self::ENDPOINT_PARAMETERS['options'].'}'],
        ];

        foreach ($endpoints as $endpoint => [$method, $path]) {
            $route = $this->router->{$method}("{$base}/{$path}", $action);
            $route->setAction([...$route->getAction(), self::ACTION_KEY => $endpoint]);
            $this->endpoints[$endpoint] = $this->handleLast($route);
        }

        $this->page = $this->router->get($uri, $action);
        $this->page->setAction([...$this->page->getAction(), self::ACTION_KEY => 'page']);

        return $this;
    }

    /**
     * The GET list page route.
     */
    public function page(): Route
    {
        return $this->page;
    }

    /**
     * @return array<string, Route> keyed by action, cell, export, options
     */
    public function endpoints(): array
    {
        return $this->endpoints;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (in_array($method, self::SHARED_MODIFIERS, true) && ! $this->isGetter($method, $arguments)) {
            foreach ([$this->page, ...array_values($this->endpoints)] as $route) {
                $route->{$method}(...$arguments);
            }

            array_map($this->handleLast(...), $this->endpoints);

            return $this;
        }

        $result = $this->page->{$method}(...$arguments);

        return $result === $this->page ? $this : $result;
    }

    /**
     * domain() and middleware() without arguments return the route's value instead of setting one.
     *
     * @param  array<int, mixed>  $arguments
     */
    protected function isGetter(string $method, array $arguments): bool
    {
        return in_array($method, ['domain', 'middleware'], true) && ($arguments[0] ?? null) === null;
    }

    /**
     * HandleGridEndpoint stays the last route middleware, right around the page action.
     */
    protected function handleLast(Route $route): Route
    {
        $action = $route->getAction();
        $middleware = array_values(array_filter(
            (array) ($action['middleware'] ?? []),
            fn ($name) => $name !== HandleGridEndpoint::class,
        ));
        $action['middleware'] = [...$middleware, HandleGridEndpoint::class];

        return $route->setAction($action);
    }
}
