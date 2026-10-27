<?php

use Brume\Dashboard\Dashboard;
use Brume\Http\Middleware\HandleBrumeRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

it('renders the home page as a dashboard with a welcome card', function () {
    $this->actingAs(brumeUser(), 'brume');

    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('brume/page')
        ->where('envelope.page.type', 'dashboard')
        ->where('envelope.page.blocks.0.type', 'card'));
});

it('collects cards and components in order, omitting empty props', function () {
    $schema = Dashboard::make('Home')
        ->card('A', 'body')
        ->component('chart', ['kind' => 'bar'])
        ->component('clock')
        ->toSchema(Request::create('/'));

    expect($schema)->toBe([
        'type' => 'dashboard',
        'title' => 'Home',
        'blocks' => [
            ['type' => 'card', 'title' => 'A', 'body' => 'body'],
            ['type' => 'component', 'name' => 'chart', 'props' => ['kind' => 'bar']],
            ['type' => 'component', 'name' => 'clock'],
        ],
    ]);
});

it('can be returned straight from a controller', function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])->get('/dash-direct', fn () => Dashboard::make('Direct'));

    $this->get('/dash-direct')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('envelope.page.title', 'Direct'));
});
