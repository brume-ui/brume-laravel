<?php

use Brume\Form\ConditionPath;
use Brume\Form\Conditions;
use Brume\Form\Form;
use Brume\Grid\Actions\Action;
use Brume\Grid\Actions\Feedback;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\JsonSchema;
use Brume\Tests\Support\Models\CellItem;
use Illuminate\Support\Facades\Route;

function quickGridRoutes(?Closure $fields = null, bool $editUrl = true): void
{
    Route::middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeGrid('quick-items', function () use ($fields, $editUrl) {
        $grid = Grid::make(CellItem::class);
        $grid->column('customer');
        if ($editUrl) {
            $grid->editUrl(fn ($row) => '/quick-items/'.$row->id.'/edit');
        }

        return $grid->quickCreate($fields ?? function (Form $form) {
            $form->text('customer')->required();
            $form->text('password')->disabled();
            $form->number('shop_id');
        });
    }));
}

beforeEach(fn () => $this->actingAs(brumeUser(), 'brume'));

it('quick creates through FormSaver and returns real edit URLs and schema', function () {
    quickGridRoutes();
    $page = $this->get('/quick-items')->assertOk()->viewData('page')['props']['envelope']['page'];
    expect(JsonSchema::errors(['schemaVersion' => '0.4.0', 'page' => $page], 'PageEnvelope'))->toBe([]);
    expect($page['quickCreate']['fields'][0]['name'])->toBe('customer')->and($page['quickCreate']['submitUrl'])->toBe('/quick-items/_brume/quick-create');
    $response = $this->postJson('/quick-items/_brume/quick-create', ['values' => ['customer' => 'Ada', 'shop_id' => 1, 'password' => 'evil', 'unknown' => 'evil']])->assertOk();
    expect(JsonSchema::errors($response->json(), 'FormSubmitResponse'))->toBe([])
        ->and($response->json('urls.edit'))->toBe('/quick-items/'.$response->json('id').'/edit')
        ->and(CellItem::find($response->json('id'))->password)->toBeNull();
});

it('uses root validation keys and never writes invalid quick input', function () {
    quickGridRoutes();
    $this->postJson('/quick-items/_brume/quick-create', ['values' => (object) []])->assertUnprocessable()->assertJsonValidationErrors('customer');
    expect(CellItem::count())->toBe(0);
});

it('propagates quick create cancellation without success fields', function () {
    quickGridRoutes(function (Form $form) {
        $form->text('customer');
        $form->saving(fn () => Feedback::toast('info', 'cancel'));
    });
    $response = $this->postJson('/quick-items/_brume/quick-create', ['values' => ['customer' => 'Ada']])->assertOk();
    expect($response->json('cancelled'))->toBeTrue()->and($response->json())->not->toHaveKeys(['id', 'urls'])->and(CellItem::count())->toBe(0);
});

it('requires configured success URLs before quick persistence', function () {
    quickGridRoutes(editUrl: false);
    $this->withoutExceptionHandling();
    expect(fn () => $this->postJson('/quick-items/_brume/quick-create', ['values' => ['customer' => 'Ada']]))->toThrow(LogicException::class);
    expect(CellItem::count())->toBe(0);
});

it('requires csrf and accepts the page encrypted xsrf cookie', function () {
    quickGridRoutes();
    $cookie = collect($this->get('/quick-items')->assertOk()->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');
    app()->detectEnvironment(fn () => 'local');
    $body = ['values' => ['customer' => 'Ada', 'shop_id' => 1]];
    $this->postJson('/quick-items/_brume/quick-create', $body)->assertStatus(419);
    expect(CellItem::count())->toBe(0);
    $this->postJson('/quick-items/_brume/quick-create', $body, ['X-XSRF-TOKEN' => $cookie->getValue()])->assertOk();
});

it('rejects upload and structural quick and action fields', function () {
    foreach ([fn (Form $form) => $form->file('document'), fn (Form $form) => $form->embeds('meta', 'Meta', fn ($nested) => $nested->text('title'))] as $fields) {
        expect(fn () => Grid::make(CellItem::class)->quickCreate($fields)->quickForm())->toThrow(InvalidArgumentException::class);
        expect(fn () => Action::make('bad', 'Bad')->form($fields))->toThrow(InvalidArgumentException::class);
    }
});

class Task21QuickResource extends Brume\Resource\Resource
{
    protected string $model = CellItem::class;

    protected string $name = '項目';

    protected function grid(Grid $grid): void
    {
        $grid->column('customer');
    }

    protected function form(Form $form): void
    {
        $form->text('customer');
    }

    protected function quickCreate(Form $form): void
    {
        $form->text('customer')->required();
        $form->number('shop_id');
    }
}

it('automatically exposes resource quick fields with mapped edit URLs', function () {
    Route::middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeResource('resource-quick', Task21QuickResource::class)->name('edit', 'custom.quick.edit'));
    Route::getRoutes()->refreshNameLookups();
    $page = $this->get('/resource-quick')->assertOk()->viewData('page')['props']['envelope']['page'];
    expect($page['quickCreate']['fields'][0]['name'])->toBe('customer');
    $response = $this->postJson('/resource-quick/_brume/quick-create', ['values' => ['customer' => 'Ada', 'shop_id' => 1]])->assertOk();
    expect($response->json('feedback.toast.message'))->toBe('已新增項目');
    $this->get($response->json('urls.edit'), ['X-Brume-Form' => '1'])->assertOk();
    expect(JsonSchema::errors($response->json(), 'FormSubmitResponse'))->toBe([]);
});

it('persists custom quick tags regardless of suggestion configuration', function (?array $options) {
    quickGridRoutes(function (Form $form) use ($options) {
        $form->text('customer');
        $form->number('shop_id');
        $field = $form->tags('tags');
        if ($options !== null) {
            $field->options($options);
        }
    });
    $response = $this->postJson('/quick-items/_brume/quick-create', ['values' => ['customer' => 'Ada', 'shop_id' => 1, 'tags' => ['custom']]])->assertOk();
    expect(CellItem::findOrFail($response->json('id'))->tags)->toBe(['custom']);
})->with(['populated' => [['a' => 'A']], 'empty' => [[]], 'omitted' => [null]]);

it('applies flat quick-create conditions using readonly defaults and strips forged hidden input', function () {
    quickGridRoutes(function (Form $form) {
        $form->text('customer')->required();
        $form->number('shop_id');
        $form->text('status')->default('open')->readOnly();
        $form->text('note')->required()->showWhen(Conditions::eq(ConditionPath::root('status'), 'paid'));
    });
    $response = $this->postJson('/quick-items/_brume/quick-create', ['values' => ['customer' => 'Ada', 'shop_id' => 1, 'status' => 'paid', 'note' => 'forged']])->assertOk();
    expect(CellItem::findOrFail($response->json('id'))->note)->toBeNull();
});

it('rejects object shaped quick-create arrays without hiding dependent validation', function () {
    quickGridRoutes(function (Form $form) {
        $form->text('customer');
        $form->number('shop_id');
        $form->tags('tags');
        $form->text('note')->required()->showWhen(Conditions::not(Conditions::empty(Conditions::root('tags'))));
    });
    $this->postJson('/quick-items/_brume/quick-create', ['values' => ['customer' => 'Ada', 'shop_id' => 1, 'tags' => (object) []]])->assertUnprocessable()->assertJsonValidationErrors(['tags', 'note']);
    $this->postJson('/quick-items/_brume/quick-create', ['values' => ['customer' => 'Ada', 'shop_id' => 1, 'tags' => []]])->assertOk();
});
