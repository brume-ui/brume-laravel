<?php

use Brume\Grid\Actions\Action;
use Brume\Grid\Actions\Feedback;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\JsonSchema;
use Brume\Tests\Support\Models\ActionItem;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * /admin/items: shop 1's items, on a Route::brumeGrid page behind brume.auth as a project's
 * routes/brume.php is. $declare adds the actions under test.
 */
function actionRoutes(Closure $declare): void
{
    Route::prefix('admin')
        ->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class, 'brume.auth'])
        ->group(fn () => Route::brumeGrid('items', function () use ($declare) {
            $grid = Grid::make(ActionItem::query()->where('shop_id', 1))->title('Items');
            $grid->column('name')->primary();
            $grid->column('status');
            $declare($grid);

            return $grid;
        }));
}

/**
 * Items 1 and 2 in shop 1, item 3 in shop 2 (ids as created: MySQL keeps counting across tests).
 *
 * @return array{0: ActionItem, 1: ActionItem, 2: ActionItem}
 */
function actionItems(): array
{
    return [
        ActionItem::query()->create(['shop_id' => 1, 'name' => 'A']),
        ActionItem::query()->create(['shop_id' => 1, 'name' => 'B']),
        ActionItem::query()->create(['shop_id' => 2, 'name' => 'C']),
    ];
}

/**
 * A row and batch action that sets status = 'done'.
 */
function closeAction(): Action
{
    return Action::make('close', '結案')->handle(function (Collection $models) {
        $models->each->update(['status' => 'done']);
    });
}

function actionPage(Closure $declare): array
{
    return actionEnvelope($declare)['page'];
}

function actionEnvelope(Closure $declare): array
{
    actionRoutes($declare);

    return test()->get('/admin/items')->assertOk()->viewData('page')['props']['envelope'];
}

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
});

it('runs a row action and answers the default feedback', function () {
    [$a, $b] = actionItems();
    actionRoutes(fn (Grid $grid) => $grid->rowActions(closeAction()));

    $response = $this->postJson('/admin/items/_brume/actions/close', ['target' => 'row', 'ids' => [$a->id]])->assertOk();

    expect(JsonSchema::errors($response->json(), 'ActionResponse'))->toBe([])
        ->and($response->json())->toBe(['feedback' => ['toast' => ['type' => 'success', 'message' => '已完成「結案」'], 'refresh' => true]])
        ->and($a->fresh()->status)->toBe('done')
        ->and($b->fresh()->status)->toBe('open');
});

it('answers the Feedback a handler returns', function () {
    [$a] = actionItems();
    actionRoutes(fn (Grid $grid) => $grid->rowActions(
        Action::make('invoice', '開發票')->handle(fn () => Feedback::toast('info', '發票已排程')
            ->redirect('/admin/invoices')->download('/files/a.pdf', 'a.pdf')),
    ));

    $response = $this->postJson('/admin/items/_brume/actions/invoice', ['target' => 'row', 'ids' => [(string) $a->id]])->assertOk();

    expect(JsonSchema::errors($response->json(), 'ActionResponse'))->toBe([])
        ->and($response->json('feedback'))->toBe([
            'toast' => ['type' => 'info', 'message' => '發票已排程'],
            'redirect' => '/admin/invoices',
            'download' => ['url' => '/files/a.pdf', 'filename' => 'a.pdf'],
        ]);
});

it('passes a batch action every selected row', function () {
    [$a, $b] = actionItems();
    $received = new ArrayObject;
    actionRoutes(fn (Grid $grid) => $grid->batchActions(Action::make('close', '結案')->handle(function (Collection $models, Request $request) use ($received) {
        $received->exchangeArray([$models->pluck('id')->sort()->values()->all(), $request->input('target')]);
    })));

    $this->postJson('/admin/items/_brume/actions/close', ['target' => 'batch', 'ids' => [$a->id, $b->id, $a->id]])->assertOk();

    expect($received->getArrayCopy())->toBe([[$a->id, $b->id], 'batch']);
});

it('passes a tool an empty collection', function () {
    actionItems();
    $received = new ArrayObject;
    actionRoutes(fn (Grid $grid) => $grid->tools(Action::make('sync', '同步')->handle(function (Collection $models) use ($received) {
        $received['models'] = $models;

        return Feedback::make()->refresh();
    })));

    $response = $this->postJson('/admin/items/_brume/actions/sync', ['target' => 'tool', 'ids' => []])->assertOk();

    expect($received['models'])->toBeInstanceOf(Collection::class)->toBeEmpty()
        ->and($response->json())->toBe(['feedback' => ['refresh' => true]]);
});

it('runs an action subclass that overrides run()', function () {
    [$a] = actionItems();
    $action = new class('lock', '鎖定') extends Action
    {
        protected function run(Collection $models, Request $request): ?Feedback
        {
            $models->each->update(['status' => 'locked']);

            return Feedback::toast('success', '已鎖定');
        }
    };
    actionRoutes(fn (Grid $grid) => $grid->rowActions($action));

    $this->postJson('/admin/items/_brume/actions/lock', ['target' => 'row', 'ids' => [$a->id]])
        ->assertOk()->assertJsonPath('feedback.toast.message', '已鎖定');

    expect($a->fresh()->status)->toBe('locked');
});

it('deletes rows one by one, firing model events and soft deleting', function (string $target, Closure $ids, string $message) {
    [$a, $b, $c] = actionItems();
    $deleted = [];
    ActionItem::deleting(function (ActionItem $item) use (&$deleted) {
        $deleted[] = $item->id;
    });
    actionRoutes(fn (Grid $grid) => $grid->deletable());

    $response = $this->postJson('/admin/items/_brume/actions/delete', ['target' => $target, 'ids' => $ids($a, $b)])->assertOk();

    expect(JsonSchema::errors($response->json(), 'ActionResponse'))->toBe([])
        ->and($response->json())->toBe(['feedback' => ['toast' => ['type' => 'success', 'message' => $message], 'refresh' => true]])
        ->and($deleted)->toEqualCanonicalizing($ids($a, $b))
        ->and(ActionItem::query()->pluck('id')->all())->toBe(array_values(array_diff([$a->id, $b->id, $c->id], $ids($a, $b))))
        ->and(ActionItem::withTrashed()->count())->toBe(3);
})->with([
    'row' => ['row', fn ($a) => [$a->id], '已刪除 1 筆'],
    'batch' => ['batch', fn ($a, $b) => [$a->id, $b->id], '已刪除 2 筆'],
]);

it('declares delete with the confirm copy on rows and batches', function () {
    $page = actionPage(fn (Grid $grid) => $grid->deletable());

    $delete = ['key' => 'delete', 'label' => '刪除', 'icon' => 'trash-2', 'tone' => 'danger'];

    expect($page['actions'])->toBe([
        'row' => [$delete + ['confirm' => ['title' => '刪除這筆資料？', 'description' => '刪除後無法復原。', 'confirmLabel' => '刪除']]],
        'batch' => [$delete + ['confirm' => ['title' => '刪除所選的 {count} 筆資料？', 'confirmLabel' => '刪除 {count} 筆']]],
        'tools' => [],
    ]);
});

it('lists delete after the declared actions, wherever deletable() is called', function () {
    $page = actionPage(fn (Grid $grid) => $grid->deletable()->rowActions(closeAction())->batchActions(closeAction()));

    expect(array_column($page['actions']['row'], 'key'))->toBe(['close', 'delete'])
        ->and(array_column($page['actions']['batch'], 'key'))->toBe(['close', 'delete']);
});

it('rejects ids outside the grid query without side effects', function (string $target, Closure $ids) {
    [$a, $b, $c] = actionItems();
    $ran = new ArrayObject;
    actionRoutes(fn (Grid $grid) => $grid->deletable()->rowActions(closeAction())
        ->batchActions(Action::make('close', '結案')->handle(function () use ($ran) {
            $ran['ran'] = true;
        })));

    $response = $this->postJson('/admin/items/_brume/actions/delete', ['target' => $target, 'ids' => $ids($a, $b, $c)])->assertNotFound();
    $this->postJson('/admin/items/_brume/actions/close', ['target' => $target, 'ids' => $ids($a, $b, $c)])->assertNotFound();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($ran->getArrayCopy())->toBe([])
        ->and(ActionItem::query()->count())->toBe(3)
        ->and(ActionItem::query()->where('status', 'done')->count())->toBe(0);
})->with([
    'another shop\'s row' => ['row', fn ($a, $b, $c) => [$c->id]],
    'a batch with another shop\'s row' => ['batch', fn ($a, $b, $c) => [$a->id, $c->id]],
    'a missing id' => ['batch', fn ($a, $b, $c) => [$b->id, $c->id + 1000]],
]);

it('rejects a soft-deleted row as outside the grid query', function () {
    [$a] = actionItems();
    $a->delete();
    actionRoutes(fn (Grid $grid) => $grid->rowActions(closeAction()));

    $this->postJson('/admin/items/_brume/actions/close', ['target' => 'row', 'ids' => [$a->id]])->assertNotFound();

    expect(ActionItem::withTrashed()->find($a->id)->status)->toBe('open');
});

it('forbids a row-hidden action without side effects', function (string $target) {
    [$a, $b] = actionItems();
    $b->update(['status' => 'locked']);
    $close = closeAction()->visible(fn (ActionItem $row) => $row->status !== 'locked');
    actionRoutes(fn (Grid $grid) => $target === 'row' ? $grid->rowActions($close) : $grid->batchActions($close));

    $ids = $target === 'row' ? [$b->id] : [$a->id, $b->id];
    $response = $this->postJson('/admin/items/_brume/actions/close', ['target' => $target, 'ids' => $ids])->assertForbidden();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($a->fresh()->status)->toBe('open')
        ->and($b->fresh()->status)->toBe('locked');
})->with(['row', 'batch']);

it('rolls back when the handler throws', function () {
    [$a, $b] = actionItems();
    actionRoutes(fn (Grid $grid) => $grid->batchActions(Action::make('close', '結案')->handle(function (Collection $models) {
        $models->first()->update(['status' => 'done']);

        throw new RuntimeException('boom');
    })));

    $this->postJson('/admin/items/_brume/actions/close', ['target' => 'batch', 'ids' => [$a->id, $b->id]])->assertServerError();

    expect(ActionItem::query()->where('status', 'done')->count())->toBe(0);
});

it('answers a ValidationException from the handler as a 422 ErrorResponse and rolls back', function () {
    [$a] = actionItems();
    actionRoutes(fn (Grid $grid) => $grid->rowActions(Action::make('close', '結案')->handle(function (Collection $models) {
        $models->each->update(['status' => 'done']);

        throw ValidationException::withMessages(['status' => '已出貨的訂單不能結案。']);
    })));

    $response = $this->postJson('/admin/items/_brume/actions/close', ['target' => 'row', 'ids' => [$a->id]])->assertUnprocessable();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($response->json('errors'))->toBe(['status' => ['已出貨的訂單不能結案。']])
        ->and($a->fresh()->status)->toBe('open');
});

it('answers 422 to a body that is not an ActionRequest', function (array $body) {
    [$a, $b] = actionItems();
    actionRoutes(fn (Grid $grid) => $grid->rowActions(closeAction())->batchActions(closeAction())->tools(closeAction()));
    // ':a' and ':b' stand for the ids of items A and B.
    array_walk_recursive($body, function (mixed &$value) use ($a, $b) {
        $value = match ($value) {
            ':a' => $a->id,
            ':b' => $b->id,
            default => $value,
        };
    });

    $response = $this->postJson('/admin/items/_brume/actions/close', $body)->assertUnprocessable();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and(ActionItem::query()->where('status', 'done')->count())->toBe(0);
})->with([
    'target all' => [['target' => 'all', 'ids' => [':a']]],
    'no target' => [['ids' => [':a']]],
    'no ids' => [['target' => 'tool']],
    'ids not a list' => [['target' => 'batch', 'ids' => ['x' => ':a']]],
    'an id that is not a string or number' => [['target' => 'batch', 'ids' => [[':a']]]],
    'a boolean id' => [['target' => 'batch', 'ids' => [true]]],
    'a float id' => [['target' => 'batch', 'ids' => [1.5]]],
    'a row with two ids' => [['target' => 'row', 'ids' => [':a', ':b']]],
    'a row with no id' => [['target' => 'row', 'ids' => []]],
    'a batch with no id' => [['target' => 'batch', 'ids' => []]],
    'a tool with ids' => [['target' => 'tool', 'ids' => [':a']]],
]);

it('answers 404 for an action the target does not declare', function (string $target, string $key) {
    [$a] = actionItems();
    actionRoutes(fn (Grid $grid) => $grid->rowActions(closeAction()));

    $response = $this->postJson("/admin/items/_brume/actions/{$key}", ['target' => $target, 'ids' => [$a->id]])->assertNotFound();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([]);
})->with([
    'unknown key' => ['row', 'nope'],
    'a row action run as a batch' => ['batch', 'close'],
]);

it('answers 401 JSON when signed out', function () {
    [$a] = actionItems();
    actionRoutes(fn (Grid $grid) => $grid->rowActions(closeAction()));
    auth('brume')->logout();

    $response = $this->postJson('/admin/items/_brume/actions/close', ['target' => 'row', 'ids' => [$a->id]])->assertUnauthorized();

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($a->fresh()->status)->toBe('open');
});

it('answers 419 without a CSRF token', function () {
    [$a] = actionItems();
    actionRoutes(fn (Grid $grid) => $grid->rowActions(closeAction()));
    // The middleware skips its check while running tests; this one checks.
    $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });

    $response = $this->postJson('/admin/items/_brume/actions/close', ['target' => 'row', 'ids' => [$a->id]])->assertStatus(419);

    expect(JsonSchema::errors($response->json(), 'ErrorResponse'))->toBe([])
        ->and($a->fresh()->status)->toBe('open');
});

it('lists the row actions each row allows in _meta.actions when one declares visible()', function () {
    actionItems();
    ActionItem::query()->where('name', 'B')->update(['status' => 'locked']);

    $page = actionPage(fn (Grid $grid) => $grid->deletable()->rowActions(
        closeAction()->visible(fn (ActionItem $row) => $row->status !== 'locked'),
        Action::make('view', '查看')->handle(fn () => null),
    ));

    expect(array_column($page['rows'], '_meta'))->toBe([
        ['actions' => ['close', 'view', 'delete']],
        ['actions' => ['view', 'delete']],
    ]);
});

it('leaves _meta.actions out when no row action declares visible()', function () {
    actionItems();

    $page = actionPage(fn (Grid $grid) => $grid->deletable()->rowActions(closeAction()));

    expect(collect($page['rows'])->pluck('_meta')->filter()->all())->toBe([]);
});

it('sends editUrl in _meta.editUrl', function () {
    [$a, $b] = actionItems();

    $page = actionPage(fn (Grid $grid) => $grid->editUrl(fn (ActionItem $row) => $row->name === 'A' ? "/admin/items/{$row->id}/edit" : null));

    expect(array_column($page['rows'], '_meta', 'id'))->toBe([$a->id => ['editUrl' => "/admin/items/{$a->id}/edit"]])
        ->and(array_key_exists('_meta', $page['rows'][1]))->toBeFalse();
});

it('validates a grid with every kind of action against PageEnvelope', function () {
    actionItems();

    $envelope = actionEnvelope(fn (Grid $grid) => $grid->deletable()
        ->editUrl(fn (ActionItem $row) => "/admin/items/{$row->id}/edit")
        ->rowActions(
            closeAction()->icon('check')->exposed()->shortcut('c')->visible(fn () => true),
            Action::make('lock', '鎖定')->danger()->confirm('鎖定這筆？')->shortcut('Enter')->handle(fn () => null),
        )
        ->batchActions(closeAction()->confirm('結案 {count} 筆？', '結案後會通知客戶。', '結案'))
        ->tools(Action::make('sync', '同步')->icon('refresh-cw')->handle(fn () => null)));

    $page = $envelope['page'];

    expect(JsonSchema::errors($envelope, 'PageEnvelope'))->toBe([])
        ->and($page['actions']['row'][0])->toBe(['key' => 'close', 'label' => '結案', 'icon' => 'check', 'shortcut' => 'c', 'exposed' => true])
        ->and($page['actions']['row'][1]['confirm'])->toBe(['title' => '鎖定這筆？', 'confirmLabel' => '鎖定'])
        ->and($page['actions']['batch'][0]['confirm'])->toBe(['title' => '結案 {count} 筆？', 'description' => '結案後會通知客戶。', 'confirmLabel' => '結案'])
        ->and($page['endpoints']['action'])->toBe('/admin/items/_brume/actions/{action}');
});

it('fails loudly in local for a grid with actions on a plain Route::get', function (Closure $declare) {
    app()->detectEnvironment(fn () => 'local');
    $grid = Grid::make(ActionItem::class)->title('Items');
    $declare($grid);

    $grid->toSchema(Request::create('/admin/items'));
})->throws(LogicException::class, 'Route::brumeGrid')->with([
    'row' => [fn (Grid $grid) => $grid->rowActions(closeAction())],
    'batch' => [fn (Grid $grid) => $grid->batchActions(closeAction())],
    'tool' => [fn (Grid $grid) => $grid->tools(closeAction())],
    'deletable' => [fn (Grid $grid) => $grid->deletable()],
]);

it('leaves the actions out and logs a warning in production for a grid on a plain Route::get', function () {
    app()->detectEnvironment(fn () => 'production');
    Log::spy();
    actionItems();
    $grid = Grid::make(ActionItem::class)->title('Items')->deletable()
        ->rowActions(closeAction()->visible(fn () => true))->tools(closeAction());
    $grid->column('name');

    $page = $grid->toSchema(Request::create('/admin/items'));

    expect($page['actions'])->toBe(['row' => [], 'batch' => [], 'tools' => []])
        ->and(array_key_exists('endpoints', $page))->toBeFalse()
        ->and(collect($page['rows'])->pluck('_meta')->filter()->all())->toBe([]);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'Route::brumeGrid'));
});

it('allows no more than two exposed row actions in local', function () {
    app()->detectEnvironment(fn () => 'local');

    Grid::make(ActionItem::class)->rowActions(
        closeAction()->exposed(),
        Action::make('a', 'A')->exposed(),
        Action::make('b', 'B')->exposed(),
    );
})->throws(LogicException::class, 'exposed()');

it('rejects invalid action declarations', function (Closure $declare) {
    $declare();
})->throws(InvalidArgumentException::class)->with([
    'a shortcut of two characters' => [fn () => Action::make('a', 'A')->shortcut('ab')],
    'an unknown shortcut name' => [fn () => Action::make('a', 'A')->shortcut('Escape')],
    'an empty key' => [fn () => Action::make('', 'A')],
    'the same key twice in rows' => [fn () => Grid::make(ActionItem::class)->rowActions(closeAction(), closeAction())],
    'a declared delete next to deletable()' => [fn () => Grid::make(ActionItem::class)->deletable()->rowActions(Action::make('delete', '刪除'))],
    'an unknown toast type' => [fn () => Feedback::toast('danger', 'x')],
]);

it('refuses a handler that returns something other than Feedback, and rolls back', function () {
    [$a] = actionItems();
    actionRoutes(fn (Grid $grid) => $grid->rowActions(Action::make('close', '結案')->handle(function (Collection $models) {
        $models->each->update(['status' => 'done']);

        return '完成';
    })));

    $this->postJson('/admin/items/_brume/actions/close', ['target' => 'row', 'ids' => [$a->id]])->assertServerError();

    expect($a->fresh()->status)->toBe('open');
});
