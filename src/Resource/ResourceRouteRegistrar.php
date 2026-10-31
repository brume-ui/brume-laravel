<?php

namespace Brume\Resource;

use Brume\Grid\GridRouteRegistrar;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/** Composes the existing grid routes with the resource form actions. */
final class ResourceRouteRegistrar
{
    public const ACTION_KEY = 'brume_resource';

    public const RECORD = 'brume_resource.record';

    public const ROUTE_NAMES = 'brume_resource_names';

    protected GridRouteRegistrar $grid;

    protected array $forms = [];

    protected string $groupName;

    public function __construct(protected Router $router) {}

    public function register(string $uri, string $controller): static
    {
        $uri = trim($uri, '/');
        $this->grid = (new GridRouteRegistrar($this->router))->register($uri, [$controller, 'index']);
        $this->groupName = $this->grid->page()->getName() ?? '';
        foreach ([
            'options' => ['get', "$uri/_brume/form-options", 'options'],
            'record-options' => ['get', "$uri/{_brume_record}/_brume/form-options", 'recordOptions'],
            'create' => ['get', "$uri/create", 'create'],
            'store' => ['post', $uri, 'store'],
            'edit' => ['get', "$uri/{_brume_record}/edit", 'edit'],
            'update' => ['put', "$uri/{_brume_record}", 'update'],
            'upload' => ['post', "$uri/_brume/upload", 'store'],
            'record-upload' => ['post', "$uri/{_brume_record}/_brume/upload", 'update'],
        ] as $key => [$method, $path, $action]) {
            $route = $this->router->{$method}($path, [$controller, $action]);
            $route->setAction([...$route->getAction(), 'brume_resource_upload' => str_ends_with($key, 'upload')]);
            if (str_ends_with($key, 'upload')) {
                $route->middleware('throttle:60,1');
            }
            $this->forms[$key] = $route;
        }
        $this->middleware(['web', 'brume.auth']);

        return $this->names(str_replace('/', '.', $uri));
    }

    public function names(string $prefix): static
    {
        $prefix = $this->groupName.$prefix;
        foreach (['index' => $this->page(), ...$this->forms] as $key => $route) {
            $action = $route->getAction();
            $action['as'] = $prefix.'.'.$key;
            $route->setAction($action);
        }
        foreach ($this->routes() as $route) {
            $route->setAction([...$route->getAction(), self::ACTION_KEY => $prefix]);
        }

        $this->syncNames();

        return $this;
    }

    /** Name one action, with the same group-name prefix Laravel resource routes receive. */
    public function name(string $action, string $name): static
    {
        $route = $action === 'index' ? $this->page() : ($this->forms[$action] ?? null);
        if ($route === null) {
            throw new \InvalidArgumentException('Unknown resource action: '.$action);
        }
        $route->setAction([...$route->getAction(), 'as' => $this->groupName.$name]);
        $this->syncNames();

        return $this;
    }

    protected function syncNames(): void
    {
        $names = [];
        foreach (['index' => $this->page(), ...$this->forms] as $key => $route) {
            $names[$key] = $route->getName();
        }
        foreach ($this->routes() as $route) {
            $route->setAction([...$route->getAction(), self::ROUTE_NAMES => $names]);
        }
    }

    public function page(): Route
    {
        return $this->grid->page();
    }

    public function routes(): array
    {
        return [$this->page(), ...array_values($this->grid->endpoints()), ...array_values($this->forms)];
    }

    public function __call(string $method, array $arguments): mixed
    {
        if (in_array($method, GridRouteRegistrar::SHARED_MODIFIERS, true) && ! (in_array($method, ['domain', 'middleware'], true) && ($arguments[0] ?? null) === null)) {
            $this->grid->{$method}(...$arguments);
            foreach ($this->forms as $route) {
                $route->{$method}(...$arguments);
            }

            return $this;
        }
        $result = $this->page()->{$method}(...$arguments);

        return $result === $this->page() ? $this : $result;
    }

    public static function takeArgument(Route $route, Request $request): void
    {
        if ($route->getAction(self::ACTION_KEY) !== null && $route->hasParameter('_brume_record')) {
            $request->attributes->set(self::RECORD, $route->parameter('_brume_record'));
            $route->forgetParameter('_brume_record');
        }
    }
}
