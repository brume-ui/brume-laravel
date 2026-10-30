<?php

use Brume\Form\Form;
use Brume\Grid\Actions\Feedback;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Models\User;
use Brume\Resource\Resource;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Order;

class Task20OrderResource extends Resource
{
    protected string $model = Order::class;

    protected string $name = '訂單';

    protected function grid(Grid $grid): void
    {
        $grid->column('number')->primary();
    }

    protected function form(Form $form): void
    {
        $form->text('number')->required();
        $form->text('customer')->required();
        $form->number('amount')->default(1);
        $form->hidden('status')->default('pending');
        $form->hidden('category')->default('general');
    }
}

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::prefix('admin')->name('brume.')->middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeResource('resource-orders', Task20OrderResource::class));
    Route::getRoutes()->refreshNameLookups();
});

it('registers all resource routes with stable names and shared modifiers', function () {
    $registrar = Route::brumeResource('guarded-orders', Task20OrderResource::class)->names('guarded')->middleware('can:manage-orders')->whereNumber('_brume_record');
    expect($registrar->routes())->toHaveCount(11);
    foreach ($registrar->routes() as $route) {
        expect($route->middleware())->toContain('can:manage-orders')->and($route->wheres)->toHaveKey('_brume_record');
    }
    expect($registrar->page()->getName())->toBe('guarded.index');
});

it('renders list and create forms with resource URLs and envelope protocol', function () {
    $page = $this->get('/admin/resource-orders')->assertOk()->viewData('page')['props']['envelope']['page'];
    expect($page['resource'])->toMatchArray(['name' => '訂單', 'createUrl' => '/admin/resource-orders/create', 'editUrl' => '/admin/resource-orders/{id}/edit', 'presentation' => 'modal']);
    $normal = $this->get('/admin/resource-orders/create')->assertOk()->viewData('page');
    expect($normal['component'])->toBe('brume/page')->and($normal['props']['envelope']['page']['type'])->toBe('form');
    $response = $this->get('/admin/resource-orders/create', ['X-Brume-Form' => '1'])->assertOk()->assertHeader('Vary');
    expect($response->headers->get('Vary'))->toContain('X-Brume-Form')->and(JsonSchema::errors($response->json(), 'PageEnvelope'))->toBe([]);
});

it('stores and updates using stable form identity and safe URLs', function () {
    $saved = $this->postJson('/admin/resource-orders', ['values' => ['number' => 'TEST20', 'customer' => 'Alice', 'amount' => 1, 'status' => 'pending', 'category' => 'general']])->assertOk();
    expect(JsonSchema::errors($saved->json(), 'FormSubmitResponse'))->toBe([]);
    $id = $saved->json('id');
    expect($saved->json('urls.edit'))->toBe('/admin/resource-orders/'.$id.'/edit');
    $edit = $this->get('/admin/resource-orders/'.$id.'/edit', ['X-Brume-Form' => '1'])->assertOk();
    expect($edit->json('page.formId'))->toBe('brume.resource-orders')->and(JsonSchema::errors($edit->json(), 'PageEnvelope'))->toBe([]);
    $this->putJson('/admin/resource-orders/'.$id, ['values' => ['number' => 'UPDATED', 'customer' => 'Bob']])->assertOk();
    expect(Order::find($id)->number)->toBe('UPDATED');
    $invalid = $this->postJson('/admin/resource-orders', ['values' => []])->assertUnprocessable();
    expect(JsonSchema::errors($invalid->json(), 'ErrorResponse'))->toBe([]);
});

it('rejects malformed record ids and returns form JSON errors', function () {
    $this->get('/admin/resource-orders/1abc/edit', ['X-Brume-Form' => '1'])->assertNotFound()->assertJsonStructure(['message']);
    $this->putJson('/admin/resource-orders/1abc', ['values' => []])->assertNotFound();
    $this->postJson('/admin/resource-orders/1abc/_brume/upload')->assertNotFound();
});

class Task20Record extends Model
{
    protected $table = 'resource_records';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['file'];

    protected $casts = ['meta' => 'array'];
}
class Task20SoftRecord extends Task20Record
{
    use SoftDeletes;
}
class Task20ScopedResource extends Resource
{
    protected string $model = Task20Record::class;

    protected string $name = '檔案';

    public static array $events = [];

    public static array $commitUsers = [];

    public static ?int $cancelId = null;

    public static bool $throwDeleted = false;

    protected function query(): Builder
    {
        return parent::query()->where('shop_id', 1);
    }

    protected function grid(Grid $grid): void
    {
        $grid->column('title');
    }

    protected function form(Form $form): void
    {
        $form->text('title')->required();
        $form->file('file')->readOnly();
        $form->multipleFile('images')->attribute('meta.images')->disabled();
        $form->file('document')->attribute('meta.document');
        $form->deleting(function ($record) {
            self::$events[] = ['deleting', $record->id];
            $record->file = 'forged-hook-path';
            if ($record->id === self::$cancelId) {
                return Feedback::toast('warning', 'cancelled');
            }
        });
        $form->deleted(function ($record) {
            self::$events[] = ['deleted', $record->id];
            if (self::$throwDeleted) {
                throw new RuntimeException('deleted hook failure');
            }
        });
    }
}
class Task20SoftResource extends Task20ScopedResource
{
    protected string $model = Task20SoftRecord::class;
}

function task20ScopedRoutes(string $controller = Task20ScopedResource::class): void
{
    Task20ScopedResource::$events = [];
    Task20ScopedResource::$cancelId = null;
    Task20ScopedResource::$throwDeleted = false;
    Route::prefix('admin')->middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeResource('files', $controller));
    Route::getRoutes()->refreshNameLookups();
}

it('scopes every record lookup before hooks or upload side effects', function () {
    task20ScopedRoutes();
    Storage::fake('local');
    $foreign = Task20Record::create(['shop_id' => 2, 'title' => 'foreign']);
    $this->get('/admin/files/'.$foreign->id.'/edit')->assertNotFound();
    $this->get('/admin/files/'.$foreign->id.'/edit', ['X-Brume-Form' => '1'])->assertNotFound()->assertJsonStructure(['message']);
    $this->putJson('/admin/files/'.$foreign->id, ['values' => ['title' => 'forged']])->assertNotFound();
    $this->post('/admin/files/'.$foreign->id.'/_brume/upload', ['field' => 'document', 'file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])->assertNotFound();
    $this->postJson('/admin/files/_brume/actions/delete', ['target' => 'row', 'ids' => [$foreign->id]])->assertNotFound();
    expect($foreign->fresh()->title)->toBe('foreign')->and(Task20ScopedResource::$events)->toBe([])->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('uses store and update controller middleware on upload routes and index on grid endpoints', function () {
    Route::prefix('admin')->middleware('web')->group(fn () => Route::brumeResource('action-guard', Task20MiddlewareResource::class));
    Route::getRoutes()->refreshNameLookups();
    foreach (['/admin/action-guard', '/admin/action-guard/_brume/export', '/admin/action-guard/create'] as $uri) {
        $this->get($uri)->assertForbidden();
    }
    foreach (['/admin/action-guard', '/admin/action-guard/_brume/upload'] as $uri) {
        $this->postJson($uri)->assertForbidden();
    }
    foreach (['/admin/action-guard/1', '/admin/action-guard/1/_brume/upload'] as $uri) {
        $this->call(str_ends_with($uri, 'upload') ? 'POST' : 'PUT', $uri)->assertForbidden();
    }
});
class Task20MiddlewareResource extends Task20OrderResource
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort(403);
        })->only(['index', 'create', 'store', 'update']);
    }
}

it('requires authentication for form header requests and all resource routes', function () {
    Auth::guard('brume')->logout();
    $this->get('/admin/resource-orders/create')->assertRedirect(route('brume.login'));
    $this->get('/admin/resource-orders/create', ['X-Brume-Form' => '1'])->assertUnauthorized();
    $this->postJson('/admin/resource-orders')->assertUnauthorized();
    $this->postJson('/admin/resource-orders/_brume/upload')->assertUnauthorized();
    $this->postJson('/admin/resource-orders/1/_brume/upload')->assertUnauthorized();
});

it('requires csrf on every resource write and accepts encrypted xsrf tokens', function () {
    task20ScopedRoutes();
    Storage::fake('local');
    $record = Task20Record::create(['title' => 'before']);
    app()->detectEnvironment(fn () => 'local');
    foreach ([['POST', '/admin/files'], ['PUT', '/admin/files/'.$record->id], ['POST', '/admin/files/_brume/upload'], ['POST', '/admin/files/'.$record->id.'/_brume/upload']] as [$method, $uri]) {
        $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(419);
    }
    $cookie = collect($this->get('/admin/files/create')->headers->getCookies())->first(fn ($c) => $c->getName() === 'XSRF-TOKEN');
    $headers = ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $cookie->getValue()];
    $this->postJson('/admin/files', ['values' => ['title' => 'created']], $headers)->assertOk();
    $this->putJson('/admin/files/'.$record->id, ['values' => ['title' => 'updated']], $headers)->assertOk();
    foreach (['/admin/files/_brume/upload', '/admin/files/'.$record->id.'/_brume/upload'] as $uri) {
        $result = $this->post($uri, ['field' => 'document', 'file' => UploadedFile::fake()->image('a.png')], $headers)->assertOk();
        expect(JsonSchema::errors($result->json(), 'UploadTempResponse'))->toBe([]);
    }
});

function task20StoredRecord(): Task20Record
{
    $record = Task20Record::create(['title' => 'files', 'file' => 'stored/hidden.txt', 'meta' => ['images' => ['stored/disabled.txt'], 'document' => 'stored/editable.txt']]);
    foreach (['stored/hidden.txt', 'stored/disabled.txt', 'stored/editable.txt', 'forged-hook-path'] as $path) {
        Storage::disk('public')->put($path, 'content');
    }

    return $record;
}
function task20ProductionCallbacks(Task20Record $record): void
{
    // Production callbacks wait for level zero, unlike Testbench's intentional test-transaction shortcut.
    $record->getConnection()->setTransactionManager(new DatabaseTransactionsManager);
}

function task20CommitScope($test): void
{
    $connection = (new Task20Record)->getConnection();
    // Leave RefreshDatabase's transaction before creating test-owned setup and a real commit.
    $connection->rollBack();
    $connection->setTransactionManager(new DatabaseTransactionsManager);
    $connection->beginTransaction();
    $user = brumeUser();
    Task20ScopedResource::$commitUsers[] = $user->id;
    $test->actingAs($user, 'brume');
}

afterEach(function () {
    // Commit tests own their transaction; never leave committed fixture rows for the MySQL job.
    Task20Record::query()->delete();
    User::whereKey(Task20ScopedResource::$commitUsers)->delete();
    Task20ScopedResource::$commitUsers = [];
});

it('captures all stored uploads before hooks and removes them only on actual outer commit', function () {
    task20ScopedRoutes();
    Storage::fake('public');
    task20CommitScope($this);
    $record = task20StoredRecord();
    task20ProductionCallbacks($record);
    $connection = $record->getConnection();
    $this->postJson('/admin/files/_brume/actions/delete', ['target' => 'row', 'ids' => [$record->id]])->assertOk();
    expect(Task20Record::find($record->id))->toBeNull()->and(Task20ScopedResource::$events)->toBe([['deleting', $record->id], ['deleted', $record->id]]);
    Storage::disk('public')->assertExists('stored/hidden.txt');
    $connection->commit();
    Storage::disk('public')->assertMissing(['stored/hidden.txt', 'stored/disabled.txt', 'stored/editable.txt']);
    Storage::disk('public')->assertExists('forged-hook-path');
});

it('preserves files and rows through actual outer rollback', function () {
    task20ScopedRoutes();
    Storage::fake('public');
    $record = task20StoredRecord();
    task20ProductionCallbacks($record);
    $connection = $record->getConnection();
    $connection->beginTransaction();
    $this->postJson('/admin/files/_brume/actions/delete', ['target' => 'row', 'ids' => [$record->id]])->assertOk();
    $connection->rollBack();
    expect($record->fresh())->not->toBeNull();
    Storage::disk('public')->assertExists(['stored/hidden.txt', 'stored/disabled.txt', 'stored/editable.txt']);
});

it('cancels the entire batch on second row Feedback without running cleanup', function () {
    task20ScopedRoutes();
    Storage::fake('public');
    task20CommitScope($this);
    $first = task20StoredRecord();
    $second = Task20Record::create(['title' => 'second', 'file' => 'stored/second.txt']);
    Storage::disk('public')->put('stored/second.txt', 'content');
    Task20ScopedResource::$cancelId = $second->id;
    task20ProductionCallbacks($first);
    $this->postJson('/admin/files/_brume/actions/delete', ['target' => 'batch', 'ids' => [$first->id, $second->id]])->assertOk()->assertJsonPath('feedback.toast.message', 'cancelled');
    expect($first->fresh())->not->toBeNull()->and($second->fresh())->not->toBeNull()->and(Task20ScopedResource::$events)->toBe([['deleting', $first->id], ['deleted', $first->id], ['deleting', $second->id]]);
    $first->getConnection()->commit();
    Storage::disk('public')->assertExists(['stored/hidden.txt', 'stored/second.txt']);
});

it('preserves model-event veto partial behavior and never calls deleted for vetoed rows', function () {
    task20ScopedRoutes();
    Storage::fake('public');
    $first = task20StoredRecord();
    $second = Task20Record::create(['title' => 'veto']);
    Task20Record::deleting(fn ($record) => $record->title === 'veto' ? false : null);
    try {
        $this->postJson('/admin/files/_brume/actions/delete', ['target' => 'batch', 'ids' => [$first->id, $second->id]])->assertOk()->assertJsonPath('feedback.toast.type', 'warning');
        expect($first->fresh())->toBeNull()->and($second->fresh())->not->toBeNull()->and(Task20ScopedResource::$events)->not->toContain(['deleted', $second->id]);
    } finally {
        Task20Record::flushEventListeners();
    }
});

it('rolls the batch back when a deleted hook throws', function () {
    task20ScopedRoutes();
    Storage::fake('public');
    $record = task20StoredRecord();
    Task20ScopedResource::$throwDeleted = true;
    $this->postJson('/admin/files/_brume/actions/delete', ['target' => 'row', 'ids' => [$record->id]])->assertStatus(500);
    expect($record->fresh())->not->toBeNull();
    Storage::disk('public')->assertExists('stored/hidden.txt');
});

it('preserves soft-deleted record files after commit', function () {
    task20ScopedRoutes(Task20SoftResource::class);
    Storage::fake('public');
    task20CommitScope($this);
    $record = task20StoredRecord();
    task20ProductionCallbacks($record);
    $this->postJson('/admin/files/_brume/actions/delete', ['target' => 'row', 'ids' => [$record->id]])->assertOk();
    $record->getConnection()->commit();
    expect(Task20SoftRecord::withTrashed()->find($record->id)->trashed())->toBeTrue();
    Storage::disk('public')->assertExists('stored/hidden.txt');
});

it('retains group parameters and names in resource URLs and cached route actions', function () {
    Route::prefix('admin/shops/{shop}')->name('shops.')->middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeResource('orders', Task20OrderResource::class)->names('purchases'));
    Route::getRoutes()->refreshNameLookups();
    $form = $this->get('/admin/shops/7/orders/create', ['X-Brume-Form' => '1'])->assertOk();
    expect($form->json('page.formId'))->toBe('shops.purchases')->and($form->json('page.submit.url'))->toBe('/admin/shops/7/orders')->and($form->json('page.uploadUrl'))->toBe('/admin/shops/7/orders/_brume/upload');
    $cached = Route::getRoutes()->compile();
    expect($cached['attributes']['shops.purchases.edit']['action']['brume_resource'])->toBe('shops.purchases');
});

it('preserves existing response Vary headers beside Inertia and Form', function () {
    Route::prefix('admin')->middleware(['web', HandleBrumeRequests::class, Task20VaryMiddleware::class])->group(fn () => Route::brumeResource('vary', Task20OrderResource::class));
    Route::getRoutes()->refreshNameLookups();
    foreach ([[], ['X-Brume-Form' => '1']] as $headers) {
        $response = $this->get('/admin/vary/create', $headers)->assertOk();
        expect($response->headers->get('Vary'))->toContain('Accept-Language')->toContain('X-Inertia')->toContain('X-Brume-Form');
    }
});
class Task20VaryMiddleware
{
    public function handle($request, $next)
    {
        $response = $next($request);
        $response->setVary('Accept-Language', false);

        return $response;
    }
}

class Task20InitializationResource extends Task20OrderResource
{
    public static int $created = 0;

    protected function form(Form $form): void
    {
        parent::form($form);
        $form->creating(function () {
            self::$created++;
        });
    }
}
it('calculates list presentation without initializing create form hooks', function () {
    Task20InitializationResource::$created = 0;
    Route::prefix('admin')->middleware(['web', HandleBrumeRequests::class])->group(fn () => Route::brumeResource('initialization', Task20InitializationResource::class));
    Route::getRoutes()->refreshNameLookups();
    $this->get('/admin/initialization')->assertOk();
    expect(Task20InitializationResource::$created)->toBe(0);
    $this->get('/admin/initialization/_brume/options/unknown', ['Accept' => 'application/json'])->assertNotFound();
    expect(Task20InitializationResource::$created)->toBe(0);
    $this->get('/admin/initialization/create', ['X-Brume-Form' => '1'])->assertOk();
    expect(Task20InitializationResource::$created)->toBe(1);
});

it('maps per-action names with Laravel group semantics while retaining form identity', function () {
    Route::prefix('admin/named')->name('named.')->middleware(['web', HandleBrumeRequests::class])->group(function () {
        Route::resource('framework', Task20OrderResource::class)->name('edit', 'custom.edit');
        Route::brumeResource('orders', Task20OrderResource::class)->names('purchases')
            ->name('edit', 'custom.edit-resource')->name('store', 'custom.store')->name('update', 'custom.update');
    });
    Route::getRoutes()->refreshNameLookups();
    expect(Route::getRoutes()->getByName('named.custom.edit')->uri())->toBe('admin/named/framework/{framework}/edit')
        ->and(Route::getRoutes()->getByName('named.custom.edit-resource')->uri())->toBe('admin/named/orders/{_brume_record}/edit');
    $create = $this->get('/admin/named/orders/create', ['X-Brume-Form' => '1'])->assertOk();
    expect($create->json('page.formId'))->toBe('named.purchases')->and($create->json('page.submit.url'))->toBe('/admin/named/orders');
    $saved = $this->postJson('/admin/named/orders', ['values' => ['number' => 'NAMED20', 'customer' => 'Alice', 'amount' => 1, 'status' => 'pending', 'category' => 'general']])->assertOk();
    expect($saved->json('urls.edit'))->toBe('/admin/named/orders/'.$saved->json('id').'/edit');
    $edit = $this->get($saved->json('urls.edit'), ['X-Brume-Form' => '1'])->assertOk();
    expect($edit->json('page.formId'))->toBe('named.purchases')->and($edit->json('page.submit.url'))->toBe('/admin/named/orders/'.$saved->json('id'));
});
