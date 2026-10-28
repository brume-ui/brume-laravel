<?php

use Brume\Grid\Actions\Action;
use Brume\Grid\Editor;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\Models\CodeItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/*
 * A string key is found only by its exact stored value, on every driver. MySQL's default
 * collation matches 'ABC' to the row 'abc'; RowLookup compares the keys the query returns with
 * the ids asked for as exact strings, so that row counts as not found there too, as on SQLite.
 */

function codeRoutes(): void
{
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->group(fn () => Route::brumeGrid('codes', function () {
            $grid = Grid::make(CodeItem::class)->title('Codes');
            $grid->column('name', '名稱')->editable(Editor::text(20));
            $grid->rowActions(Action::make('rename', '改名')->handle(function (Collection $models) {
                $models->each->update(['name' => 'renamed']);
            }));

            return $grid;
        }));
}

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
    CodeItem::query()->create(['code' => 'abc', 'name' => 'A']);
    CodeItem::query()->create(['code' => 'xyz', 'name' => 'X']);
});

it('matches a key in another case in the database on MySQL only', function () {
    expect(CodeItem::query()->whereKey('ABC')->exists())->toBe(CodeItem::query()->getConnection()->getDriverName() === 'mysql');
});

it('finds string keys by their exact value only', function (array $ids, ?array $found) {
    $rows = Grid::make(CodeItem::class)->rows();

    expect($rows->find($ids)?->modelKeys())->toBe($found);
})->with([
    'exact' => [['abc'], ['abc']],
    'exact, two' => [['abc', 'xyz'], ['abc', 'xyz']],
    'another case' => [['ABC'], null],
    'both cases' => [['abc', 'ABC'], null],
    'unknown' => [['nope'], null],
]);

it('answers 404 for an action on a key in another case, without running it', function () {
    codeRoutes();

    $this->postJson('/admin/codes/_brume/actions/rename', ['target' => 'row', 'ids' => ['ABC']])->assertNotFound();
    expect(CodeItem::query()->find('abc')->name)->toBe('A');

    $this->postJson('/admin/codes/_brume/actions/rename', ['target' => 'row', 'ids' => ['abc']])->assertOk();
    expect(CodeItem::query()->find('abc')->name)->toBe('renamed');
});

it('answers 404 for a cell update on a key in another case, without saving', function () {
    codeRoutes();

    $this->patchJson('/admin/codes/_brume/cells/ABC', ['column' => 'name', 'value' => 'B'])->assertNotFound();
    expect(CodeItem::query()->find('abc')->name)->toBe('A');

    $this->patchJson('/admin/codes/_brume/cells/abc', ['column' => 'name', 'value' => 'B'])
        ->assertOk()->assertJsonPath('row.id', 'abc');
    expect(CodeItem::query()->find('abc')->name)->toBe('B');
});
