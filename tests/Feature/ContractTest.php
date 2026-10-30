<?php

use Brume\Dashboard\Dashboard;
use Brume\Facades\Brume;
use Brume\Form\Form;
use Brume\Grid\Grid;
use Brume\Models\User;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Http\Request;

/**
 * The real payload: what Brume::render() hands to Inertia, read from an Inertia (X-Inertia) response.
 * Decoded to objects, so an empty JSON object stays an object rather than becoming a PHP [].
 */
function envelope(Grid|Dashboard|Form $page, array $query = []): object
{
    $request = Request::create('/', 'GET', $query);
    $request->headers->set('X-Inertia', 'true');
    app()->instance('request', $request);

    return Brume::render($page)->toResponse($request)->getData()->props->envelope;
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

it('validates grids with sorting, quick search and an empty state against the v0 schema', function () {
    foreach (range(1, 12) as $i) {
        User::query()->create(['username' => "u{$i}", 'password' => 'x', 'name' => "N{$i}"]);
    }

    $grid = Grid::make(User::query())->title('Users')->perPage(5)->id('users')
        ->quickSearch('username', 'name')
        ->quickSearchPlaceholder('搜尋帳號')
        ->perPageOptions([5, 25])
        ->defaultSort('name', 'desc')
        ->emptyState('還沒有使用者', '新增後會顯示在這裡。', '新增使用者', '/admin/users/create');
    $grid->column('id')->numeric()->sortable();
    $grid->column('username')->sortable()->primary()->width('12rem');
    $grid->column('name')->sortable(fn ($query, $direction) => $query->orderBy('name', $direction))->hideable(false);
    $grid->column('avatar')->hidden();
    $grid->column('profile.name')->component('plain');

    $bare = Grid::make(User::query())->title('Bare')->quickSearch('name')->emptyState('空', '沒有資料');

    foreach ([[], ['sort' => '-username'], ['q' => 'u1', 'page' => '9'], ['q' => 'nobody'], ['per_page' => '25'], ['sort' => 'password', 'per_page' => '7']] as $query) {
        expect(JsonSchema::errors(envelope($grid, $query), 'PageEnvelope'))->toBe([])
            ->and(JsonSchema::errors(envelope($bare, $query), 'PageEnvelope'))->toBe([]);
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

it('validates complete create and edit forms against the emitted schema 0.3', function () {
    $form = Form::make(User::class)->name('User')->description('All fields')->submitTo('post', '/users')->tabs();
    $form->section('Basics', function ($f) {
        foreach (['text', 'textarea', 'email', 'url', 'mobile', 'password', 'hidden', 'display', 'number', 'decimal', 'currency', 'date', 'datetime', 'select', 'multipleSelect', 'radio', 'checkbox', 'switch', 'tags', 'image', 'multipleImage', 'file', 'multipleFile'] as $type) {
            $field = $f->$type($type);
            if (in_array($type, ['select', 'multipleSelect', 'radio', 'checkbox'])) {
                $field->options([1 => 'One']);
            }
        }
        $f->dateRange('range', 'Range', 'start', 'end');
        $f->datetimeRange('moments', 'Moments', 'starts_at', 'ends_at');
    });
    $form->section('Layouts', function ($f) {
        $f->row(function ($r) {
            $r->text('left')->width(6);
            $r->text('right')->width(6);
        });
        $f->columns(function ($c) {
            $c->column(12, fn ($col) => $col->block(null, fn ($b) => $b->divider()));
        });
        $f->embeds('meta', 'Meta', fn ($e) => $e->text('color')->default('blue'));
        $f->multipleImage('gallery')->extra(fn ($e) => $e->text('caption'));
    });
    expect(JsonSchema::errors(envelope($form), 'PageEnvelope'))->toBe([]);
    $form->forRecord((new User)->forceFill(['id' => 9, 'name' => 'Alice']));
    expect(JsonSchema::errors(envelope($form), 'PageEnvelope'))->toBe([]);
    expect(JsonSchema::errors(envelope(Form::make(User::class)->submitTo('post', '/')), 'PageEnvelope'))->toBe([]);
});
