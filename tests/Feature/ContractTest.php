<?php

use Brume\Dashboard\Dashboard;
use Brume\Grid\Grid;
use Brume\Models\User;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Http\Request;

function envelope(Grid|Dashboard $page, array $query = []): array
{
    return ['schemaVersion' => '0.1.0', 'page' => $page->toSchema(Request::create('/', 'GET', $query))];
}

it('validates dashboards against the v0 schema', function () {
    $empty = Dashboard::make('Empty');
    $full = Dashboard::make('Full')->card('Hi', 'there')->component('chart', ['kind' => 'bar'])->component('clock');

    expect(JsonSchema::errors(envelope($empty), 'PageEnvelope'))->toBe([])
        ->and(JsonSchema::errors(envelope($full), 'PageEnvelope'))->toBe([]);
});

it('validates grids against the v0 schema', function () {
    foreach (range(1, 12) as $i) {
        User::query()->create(['username' => "u{$i}", 'password' => 'x', 'name' => "N{$i}"]);
    }

    $grid = Grid::make(User::query()->orderBy('id'))->title('Users')->description('All users')->perPage(5);
    $grid->column('id')->numeric();
    $grid->column('name')->display(fn ($v) => strtoupper($v));
    $grid->column('username')->component('status-badge', ['tone' => 'success']);
    $grid->column('created_at');
    $grid->column('avatar');
    $grid->column('profile.name')->component('plain');

    foreach ([[], ['page' => '3'], ['page' => '999'], ['page' => 'abc']] as $query) {
        expect(JsonSchema::errors(envelope($grid, $query), 'PageEnvelope'))->toBe([]);
    }
});

it('validates an empty grid and a grid with no columns', function () {
    $empty = Grid::make(User::query())->title('None');
    $empty->column('name');

    expect(JsonSchema::errors(envelope($empty), 'PageEnvelope'))->toBe([])
        ->and(JsonSchema::errors(envelope(Grid::make(User::query())->title('Bare')), 'PageEnvelope'))->toBe([]);
});

it('encodes empty component props as an omitted key, never as a list', function () {
    $json = json_encode(envelope(Dashboard::make('D')->component('clock')));

    expect($json)->not->toContain('"props"');

    $withProps = envelope(Dashboard::make('D')->component('clock', ['a' => 1]));
    expect(json_encode($withProps))->toContain('"props":{"a":1}');
});
