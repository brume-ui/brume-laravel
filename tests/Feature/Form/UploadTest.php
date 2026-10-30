<?php

use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Models\User;
use Brume\Tests\Support\JsonSchema;
use Brume\Uploads\TempUpload;
use Brume\Uploads\TempUploads;
use Brume\Uploads\UploadController;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

it('stores sniffed temporary uploads with bound ownership', function () {
    Storage::fake('local');
    $this->actingAs(brumeUser(), 'brume');
    $form = Form::make(User::class)->name('users')->id('users');
    $field = $form->image('avatar');
    $result = app(TempUploads::class)->store(UploadedFile::fake()->image('avatar.png'), $form, 'avatar', $field);
    expect($result['token'])->toMatch('/^[A-Za-z0-9]{40}$/')->and($result['previewUrl'])->toBeString();
    $this->assertDatabaseHas('brume_uploads', ['token' => $result['token'], 'field' => 'avatar']);
    expect(app(TempUploads::class)->claim($result['token'], $form, 'avatar'))->not->toBeNull();
});

function uploadForm(): Form
{
    $form = Form::make(User::class)->id('users')->urls(fn ($r) => ['edit' => '/users/'.$r->getKey()]);
    $form->image('name');

    return $form;
}

it('archives before string persistence and retains temp through outer rollback and retry', function () {
    Storage::fake('local');
    Storage::fake('public');
    $user = brumeUser();
    $this->actingAs($user, 'brume');
    $form = uploadForm()->forRecord($user);
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'name', $form->fields()['name']);
    $connection = $user->getConnection();
    $connection->beginTransaction();
    (new FormSaver)->save($form, $user, Request::create('/', 'PUT', ['values' => ['name' => ['token' => $upload['token']]]]));
    expect($user->fresh()->name)->toStartWith('brume/users/');
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
    expect(app(TempUploads::class)->claim($upload['token'], $form, 'name'))->toBeNull();
    $connection->rollBack();
    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect(app(TempUploads::class)->claim($upload['token'], $form, 'name'))->not->toBeNull();
    (new FormSaver)->save(uploadForm(), $user->fresh(), Request::create('/', 'PUT', ['values' => ['name' => ['token' => $upload['token']]]]));
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('cleans expired rows and old untracked temporary files', function () {
    Storage::fake('local');
    $this->actingAs(brumeUser(), 'brume');
    $form = uploadForm();
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'name', $form->fields()['name']);
    $active = app(TempUploads::class)->store(UploadedFile::fake()->image('active.png'), $form, 'name', $form->fields()['name']);
    TempUpload::where('token', $upload['token'])->update(['expires_at' => now()->subMinute()]);
    Storage::disk('local')->put('brume-tmp/orphan.png', 'orphan');
    touch(Storage::disk('local')->path('brume-tmp/orphan.png'), now()->subDays(2)->timestamp);
    $this->artisan('brume:uploads:clean')->assertExitCode(0);
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
    $this->assertDatabaseHas('brume_uploads', ['token' => $active['token']]);
    $this->assertDatabaseMissing('brume_uploads', ['token' => $upload['token']]);
});

it('omits preview for non images and returns the shared response schema', function () {
    Storage::fake('local');
    $this->actingAs(brumeUser(), 'brume');
    $form = Form::make(User::class)->id('files');
    $field = $form->file('name');
    $result = app(TempUploads::class)->store(UploadedFile::fake()->createWithContent('a.txt', 'hello'), $form, 'name', $field);
    expect(JsonSchema::errors($result, 'UploadTempResponse'))->toBe([])->and($result)->not->toHaveKey('previewUrl');
});

it('preflights all tokens and paths before copying anything', function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->actingAs(brumeUser(), 'brume');
    $user = uploadRecord();
    $form = Form::make(UploadRecord::class)->id('users')->forRecord($user)->urls(fn () => ['edit' => '/users/1']);
    $field = $form->multipleImage('images');
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'images', $field);
    expect(fn () => (new FormSaver)->save($form, $user, Request::create('/', 'PUT', ['values' => ['images' => [['token' => $upload['token']], ['path' => '../../.env', 'url' => 'x']]]])))->toThrow(ValidationException::class);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect(app(TempUploads::class)->claim($upload['token'], $form, 'images'))->not->toBeNull();
});

it('isolates tokens across users guards forms fields root records and expiry', function ($change) {
    Storage::fake('local');
    $user = brumeUser();
    $other = brumeUser(['username' => 'other']);
    $this->actingAs($user, 'brume');
    $form = uploadForm()->forRecord($user);
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'name', $form->fields()['name']);
    $target = uploadForm()->forRecord($user);
    $field = 'name';
    match ($change) {
        'user' => $this->actingAs($other, 'brume'),
        'guard' => config(['brume.auth.guard' => 'web']),
        'form' => $target->id('other-form'),
        'field' => $field = 'avatar',
        'record' => $target->forRecord($other),
        'create' => $target->forRecord(null),
        'expired' => TempUpload::where('token', $upload['token'])->update(['expires_at' => now()->subSecond()]),
    };
    if ($change === 'guard') {
        $this->actingAs($other, 'web');
    }
    expect(app(TempUploads::class)->claim($upload['token'], $target, $field))->toBeNull();
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
})->with(['user', 'guard', 'form', 'field', 'record', 'create', 'expired']);

it('rejects fake png svg oversize and undeclared fields without temporary files', function ($kind) {
    Storage::fake('local');
    $this->actingAs(brumeUser(), 'brume');
    $form = uploadForm();
    $field = $form->fields()['name'];
    $file = match ($kind) {
        'php' => UploadedFile::fake()->createWithContent('evil.png', '<?php echo "evil";'),
        'svg' => UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        default => UploadedFile::fake()->image('a.png')->size(2),
    };
    if ($kind === 'size') {
        $field->maxSize(1);
    }
    expect(fn () => app(TempUploads::class)->store($file, $form, $kind === 'field' ? 'undeclared' : 'name', $field))->toThrow(ValidationException::class);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['php', 'svg', 'size', 'field']);

it('previews only owner images with restrictive headers', function () {
    Storage::fake('local');
    $user = brumeUser();
    $this->actingAs($user, 'brume');
    $form = uploadForm();
    $image = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'name', $form->fields()['name']);
    $this->get($image['previewUrl'])->assertOk()->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "default-src 'none'")
        ->assertHeader('Content-Disposition', 'inline');
    $this->actingAs(brumeUser(['username' => 'other']), 'brume');
    $this->get($image['previewUrl'])->assertNotFound();
    $this->actingAs($user, 'brume');
    TempUpload::where('token', $image['token'])->update(['mime' => 'text/plain']);
    $this->get($image['previewUrl'])->assertNotFound();
    $this->get('/admin/_brume/uploads/bad')->assertNotFound();
});

function uploadRoutes(): void
{
    Route::middleware(['web', 'brume.auth', 'throttle:60,1'])->post('/test-upload', function (Request $request) {
        return app(UploadController::class)->store(uploadForm(), $request);
    });
    Route::middleware('web')->get('/test-upload-session', fn () => response('session'));
}

it('requires csrf and accepts the encrypted xsrf header on a real controller route', function () {
    Storage::fake('local');
    $this->actingAs(brumeUser(), 'brume');
    uploadRoutes();
    app()->detectEnvironment(fn () => 'local');
    $this->post('/test-upload', ['field' => 'name', 'file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])->assertStatus(419);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    $cookie = collect($this->get('/test-upload-session')->headers->getCookies())->first(fn ($c) => $c->getName() === 'XSRF-TOKEN');
    $response = $this->post('/test-upload', ['field' => 'name', 'file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $cookie->getValue()])->assertOk();
    expect(JsonSchema::errors($response->json(), 'UploadTempResponse'))->toBe([]);
    $this->post('/test-upload', ['field' => 'missing', 'file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $cookie->getValue()])->assertUnprocessable()->assertJsonValidationErrors('field');
});

it('requires stable explicit ids before any upload effects', function () {
    Storage::fake('local');
    $this->actingAs(brumeUser(), 'brume');
    $form = Form::make(User::class)->submitTo('post', '/');
    $field = $form->image('name');
    expect(fn () => app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'name', $field))->toThrow(LogicException::class);
    expect(fn () => $form->toSchema(Request::create('/')))->toThrow(LogicException::class);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    $this->assertDatabaseCount('brume_uploads', 0);
});

it('keeps temporary content and claimability when saved hook fails then accepts same token retry', function () {
    Storage::fake('local');
    Storage::fake('public');
    $user = brumeUser();
    $this->actingAs($user, 'brume');
    $form = uploadForm()->forRecord($user)->saved(fn () => throw new RuntimeException('saved failed'));
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'name', $form->fields()['name']);
    $request = Request::create('/', 'PUT', ['values' => ['name' => ['token' => $upload['token']]]]);
    expect(fn () => (new FormSaver)->save($form, $user, $request))->toThrow(RuntimeException::class, 'saved failed');
    expect(Storage::disk('local')->allFiles())->toHaveCount(1)->and(Storage::disk('public')->allFiles())->toBe([]);
    expect(app(TempUploads::class)->claim($upload['token'], $form, 'name'))->not->toBeNull();
    (new FormSaver)->save(uploadForm(), $user->fresh(), $request);
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('uses original null editability on create and archives a nonnull string column before insert', function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->actingAs(brumeUser(), 'brume');
    $form = Form::make(User::class)->id('create')->urls(fn ($r) => ['edit' => '/users/'.$r->getKey()]);
    $field = $form->image('name')->readOnly(fn ($record) => $record !== null);
    $form->text('username');
    $form->password('password');
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'name', $field);
    $response = (new FormSaver)->save($form, null, Request::create('/', 'POST', ['values' => ['name' => ['token' => $upload['token']], 'username' => 'created', 'password' => 'secret']]));
    expect(User::findOrFail($response['id'])->name)->toStartWith('brume/create/');
});

class UploadRecord extends Model
{
    public $timestamps = false;

    protected $table = 'upload_records';

    protected $guarded = [];

    protected $casts = ['images' => 'array'];
}

function uploadRecord(array $values = []): UploadRecord
{
    return UploadRecord::create(['images' => $values]);
}

it('keeps declared extra dictionaries and reordered owned paths and deletes removals only at commit', function () {
    Storage::fake('public');
    $record = uploadRecord([['path' => 'old/a.png', 'extra' => ['0' => 'old']], ['path' => 'old/b.png', 'extra' => ['0' => 'old']]]);
    Storage::disk('public')->put('old/a.png', 'a');
    Storage::disk('public')->put('old/b.png', 'b');
    $form = Form::make(UploadRecord::class)->id('images')->urls(fn () => ['edit' => '/images/1']);
    $form->multipleImage('images')->extra(fn ($extra) => $extra->text('0')->required());
    $connection = $record->getConnection();
    $connection->beginTransaction();
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['images' => [['path' => 'old/b.png', 'extra' => ['0' => 'changed', 'unknown' => 'bad']], ['path' => 'old/a.png', 'extra' => ['0' => 'kept']]]]]));
    expect($record->fresh()->images)->toEqual([['path' => 'old/b.png', 'extra' => ['0' => 'changed']], ['path' => 'old/a.png', 'extra' => ['0' => 'kept']]]);
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['images' => [['path' => 'old/b.png', 'extra' => ['0' => 'changed']]]]]));
    Storage::disk('public')->assertExists('old/a.png');
    $connection->commit();
    Storage::disk('public')->assertMissing('old/a.png');
    Storage::disk('public')->assertExists('old/b.png');
});

it('validates extras at the full item path before copying a valid token', function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->actingAs(brumeUser(), 'brume');
    $record = uploadRecord();
    $form = Form::make(UploadRecord::class)->id('images')->forRecord($record)->urls(fn () => ['edit' => '/images/1']);
    $field = $form->multipleImage('images')->extra(fn ($extra) => $extra->url('link')->required());
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'images', $field);
    try {
        (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['images' => [['token' => $upload['token'], 'extra' => ['link' => 'bad']]]]]));
        test()->fail('Expected invalid extra');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('images.0.extra.link');
    }
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('does not trust a saving hook mutation as database path ownership', function () {
    Storage::fake('public');
    $user = brumeUser(['name' => 'old/owned.png']);
    $form = uploadForm()->saving(function ($record) {
        $record->name = 'other/stolen.png';
    });
    Storage::disk('public')->put('old/owned.png', 'own');
    Storage::disk('public')->put('other/stolen.png', 'other');
    expect(fn () => (new FormSaver)->save($form, $user, Request::create('/', 'PUT', ['values' => ['name' => ['path' => 'other/stolen.png']]])))->toThrow(ValidationException::class);
    Storage::disk('public')->assertExists('old/owned.png');
    Storage::disk('public')->assertExists('other/stolen.png');
});

it('keeps each upload callback independent across two saves in one outer transaction', function ($commit) {
    Storage::fake('local');
    Storage::fake('public');
    $user = brumeUser(['name' => 'old/owned.png']);
    $this->actingAs($user, 'brume');
    Storage::disk('public')->put('old/owned.png', 'old');
    $form = uploadForm()->forRecord($user);
    $a = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'name', $form->fields()['name']);
    $b = app(TempUploads::class)->store(UploadedFile::fake()->image('b.png'), $form, 'name', $form->fields()['name']);
    $connection = $user->getConnection();
    $connection->beginTransaction();
    foreach ([$a, $b] as $upload) {
        (new FormSaver)->save(uploadForm(), $user, Request::create('/', 'PUT', ['values' => ['name' => ['token' => $upload['token']]]]));
    }
    $final = $user->name;
    expect(Storage::disk('public')->allFiles())->toHaveCount(3)->and(Storage::disk('local')->allFiles())->toHaveCount(2);
    $commit ? $connection->commit() : $connection->rollBack();
    expect(Storage::disk('public')->allFiles())->toBe([$commit ? $final : 'old/owned.png']);
    expect(Storage::disk('local')->allFiles())->toHaveCount($commit ? 0 : 2);
})->with([true, false]);

it('uses an allowed secondary model connection for store save preview and cleanup', function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->actingAs(brumeUser(), 'brume');
    config(['database.connections.upload_secondary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $schema = Schema::connection('upload_secondary');
    $schema->create('upload_records', function (Blueprint $table) {
        $table->id();
        $table->json('images')->nullable();
    });
    $default = config('database.default');
    config(['database.default' => 'upload_secondary']);
    (require __DIR__.'/../../../database/migrations/2026_10_29_000000_create_brume_uploads_table.php')->up();
    config(['database.default' => $default]);
    $record = (new UploadRecord)->setConnection('upload_secondary');
    $record->save();
    $form = Form::make($record->newQuery())->id('secondary')->forRecord($record)->urls(fn () => ['edit' => '/secondary/1']);
    $field = $form->multipleImage('images');
    expect(fn () => app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'images', $field))->toThrow(LogicException::class);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    config(['brume.uploads.connections' => [$default, 'upload_secondary']]);
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'images', $field);
    $this->get($upload['previewUrl'])->assertOk();
    $this->assertDatabaseCount('brume_uploads', 0);
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['images' => [['token' => $upload['token']]]]]));
    expect($record->fresh()->images[0])->toStartWith('brume/secondary/');
    $this->assertDatabaseCount('brume_uploads', 0, 'upload_secondary');
    $expired = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'images', $field);
    TempUpload::on('upload_secondary')->where('token', $expired['token'])->update(['expires_at' => now()->subSecond()]);
    $this->artisan('brume:uploads:clean')->assertExitCode(0);
    $this->assertDatabaseCount('brume_uploads', 0, 'upload_secondary');
    expect(Storage::disk('local')->allFiles())->toBe([]);
    DB::purge('upload_secondary');
});

it('validates upload value shape and max before the saving hook', function () {
    $user = brumeUser();
    $called = false;
    $form = uploadForm()->saving(function () use (&$called) {
        $called = true;
    });
    expect(fn () => (new FormSaver)->save($form, $user, Request::create('/', 'PUT', ['values' => ['name' => 'forged']])))->toThrow(ValidationException::class);
    expect($called)->toBeFalse();
});

it('refuses replayed and duplicate submitted tokens without additional archived files', function () {
    Storage::fake('local');
    Storage::fake('public');
    $user = brumeUser();
    $this->actingAs($user, 'brume');
    $form = uploadForm()->forRecord($user);
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'name', $form->fields()['name']);
    $request = Request::create('/', 'PUT', ['values' => ['name' => ['token' => $upload['token']]]]);
    (new FormSaver)->save($form, $user, $request);
    expect(fn () => (new FormSaver)->save(uploadForm(), $user->fresh(), $request))->toThrow(ValidationException::class);
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('uses the configured guard even when the default guard has a different user', function () {
    Storage::fake('local');
    $owner = brumeUser();
    $other = brumeUser(['username' => 'web-user']);
    $this->actingAs($owner, 'brume');
    $this->actingAs($other, 'web');
    $form = uploadForm();
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'name', $form->fields()['name']);
    expect(app(TempUploads::class)->claim($upload['token'], $form, 'name')->user_id)->toBe((string) $owner->id);
});

it('rejects duplicate tokens in a single submission before copying files', function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->actingAs(brumeUser(), 'brume');
    $record = uploadRecord();
    $form = Form::make(UploadRecord::class)->id('images')->forRecord($record)->urls(fn () => ['edit' => '/images/1']);
    $field = $form->multipleImage('images');
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'images', $field);
    expect(fn () => (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['images' => [['token' => $upload['token']], ['token' => $upload['token']]]]])))->toThrow(ValidationException::class);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('prevents two overlapping sqlite transactions consuming the same token', function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->actingAs(brumeUser(), 'brume');
    $database = tempnam(sys_get_temp_dir(), 'brume-claims-');
    $default = config('database.default');
    foreach (['claim_a', 'claim_b'] as $name) {
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'busy_timeout' => 0]]);
    }
    config(['brume.uploads.connections' => ['claim_a', 'claim_b']]);
    $manager = new DatabaseTransactionsManager;
    foreach (['claim_a', 'claim_b'] as $name) {
        DB::connection($name)->setTransactionManager($manager);
    }
    $schema = Schema::connection('claim_a');
    $schema->create('upload_records', function (Blueprint $table) {
        $table->id();
        $table->json('images')->nullable();
    });
    config(['database.default' => 'claim_a']);
    (require __DIR__.'/../../../database/migrations/2026_10_29_000000_create_brume_uploads_table.php')->up();
    config(['database.default' => $default]);
    $record = (new UploadRecord)->setConnection('claim_a');
    $record->save();
    $other = (new UploadRecord)->setConnection('claim_b')->newQuery()->findOrFail($record->id);
    $formFor = function ($record) {
        $form = Form::make($record->newQuery())->id('claims')->forRecord($record)->urls(fn () => ['edit' => '/claims/1']);
        $form->multipleImage('images');

        return $form;
    };
    $form = $formFor($record);
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'images', $form->fields()['images']);
    $request = Request::create('/', 'PUT', ['values' => ['images' => [['token' => $upload['token']]]]]);
    $connection = $record->getConnection();
    try {
        $connection->beginTransaction();
        (new FormSaver)->save($form, $record, $request);
        expect(fn () => (new FormSaver)->save($formFor($other), $other, $request))->toThrow(QueryException::class);
        expect(Storage::disk('public')->allFiles())->toHaveCount(1);
        $connection->commit();
        expect(fn () => (new FormSaver)->save($formFor($other), $other, $request))->toThrow(ValidationException::class);
        expect(Storage::disk('public')->allFiles())->toHaveCount(1)->and(Storage::disk('local')->allFiles())->toBe([]);
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach (['claim_a', 'claim_b'] as $name) {
            DB::purge($name);
        }
        unlink($database);
    }
});

it('requires a json cast for structured upload values before copying files', function () {
    Storage::fake('local');
    Storage::fake('public');
    $user = brumeUser();
    $this->actingAs($user, 'brume');
    $form = uploadForm()->forRecord($user);
    $field = $form->fields()['name']->extra(fn ($extra) => $extra->text('caption'));
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'name', $field);
    expect(fn () => (new FormSaver)->save($form, $user, Request::create('/', 'PUT', ['values' => ['name' => ['token' => $upload['token'], 'extra' => ['caption' => 'caption']]]])))->toThrow(LogicException::class);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('saves reloads edits and clears a single upload with declared extras', function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->actingAs(brumeUser(), 'brume');
    $record = uploadRecord();
    $form = Form::make(UploadRecord::class)->id('single-extra')->forRecord($record)->urls(fn () => ['edit' => '/images/1']);
    $field = $form->image('images')->extra(fn ($extra) => $extra->text('caption')->required());
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'images', $field);
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['images' => ['token' => $upload['token'], 'extra' => ['caption' => 'First', 'unknown' => 'bad']]]]));
    $stored = $record->fresh()->images;
    expect($stored['path'])->toStartWith('brume/single-extra/')->and($stored['extra'])->toEqual(['caption' => 'First']);
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['images' => ['path' => $stored['path'], 'extra' => ['caption' => 'Changed']]]]));
    expect($record->fresh()->images['extra']['caption'])->toBe('Changed');
    expect(fn () => (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['images' => ['path' => $stored['path']]]])))->toThrow(ValidationException::class);
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['images' => null]]));
    expect($record->fresh()->images)->toBeNull();
    Storage::disk('public')->assertMissing($stored['path']);
});

it('rolls back archived copies when transactional token consumption is refused', function () {
    Storage::fake('local');
    Storage::fake('public');
    $user = brumeUser();
    $this->actingAs($user, 'brume');
    $form = uploadForm()->forRecord($user);
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'name', $form->fields()['name']);
    TempUpload::deleting(fn () => false);
    try {
        expect(fn () => (new FormSaver)->save($form, $user, Request::create('/', 'PUT', ['values' => ['name' => ['token' => $upload['token']]]])))->toThrow(RuntimeException::class);
        expect(Storage::disk('public')->allFiles())->toBe([]);
        expect(app(TempUploads::class)->claim($upload['token'], $form, 'name'))->not->toBeNull();
    } finally {
        TempUpload::flushEventListeners();
    }
});

it('rejects executable extensions for unrestricted and explicitly accepted general files', function ($content, $mime, $explicit) {
    Storage::fake('local');
    $this->actingAs(brumeUser(), 'brume');
    $form = Form::make(User::class)->id('files');
    $field = $form->file('name');
    if ($explicit) {
        $field->accept([$mime]);
    }
    expect(fn () => app(TempUploads::class)->store(UploadedFile::fake()->createWithContent('disguised.txt', $content), $form, 'name', $field))->toThrow(ValidationException::class);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    ['<?php echo "execution";', 'text/x-php', false],
    ['<html><script>alert(1)</script></html>', 'text/html', false],
    ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml', false],
    ['<?php echo "execution";', 'text/x-php', true],
    ['<html><script>alert(1)</script></html>', 'text/html', true],
    ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml', true],
]);

it('accepts inert pdf text and zip files using server derived extensions', function ($content, $extension) {
    Storage::fake('local');
    $this->actingAs(brumeUser(), 'brume');
    $form = Form::make(User::class)->id('files');
    $field = $form->file('name');
    app(TempUploads::class)->store(UploadedFile::fake()->createWithContent('wrong.php', $content), $form, 'name', $field);
    expect(Storage::disk('local')->allFiles()[0])->toEndWith('.'.$extension);
})->with([
    ["%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF", 'pdf'],
    ['A plain text document', 'txt'],
    [base64_decode('UEsFBgAAAAAAAAAAAAAAAAAAAAAAAA=='), 'zip'],
]);

it('applies complete extra item rules at full prefixes before copying any file', function ($type, $value, $message) {
    Storage::fake('local');
    Storage::fake('public');
    $this->actingAs(brumeUser(), 'brume');
    $record = uploadRecord();
    $form = Form::make(UploadRecord::class)->id('extra-rules')->forRecord($record)->urls(fn () => ['edit' => '/images/1']);
    $field = $form->multipleImage('images')->extra(function ($extra) use ($type) {
        $definition = $extra->$type('roles', 'Roles label')->messages(['in' => ':attribute rejected choice', 'string' => ':attribute rejected type', 'max' => ':attribute rejected length']);
        if ($type === 'tags') {
            $definition->maxLength(3);
        } else {
            $definition->options(['a' => 'A']);
        }
    });
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'images', $field);
    // The first item is valid; only the last item's metadata is invalid.
    $other = app(TempUploads::class)->store(UploadedFile::fake()->image('b.png'), $form, 'images', $field);
    try {
        (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['images' => [['token' => $upload['token'], 'extra' => ['roles' => ['a']]], ['token' => $other['token'], 'extra' => ['roles' => $value]]]]]));
        test()->fail('Expected invalid extra list item');
    } catch (ValidationException $e) {
        expect($e->errors()['images.1.extra.roles.0'][0])->toBe('Roles label '.$message);
    }
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    ['multipleSelect', ['bad'], 'rejected choice'],
    ['checkbox', [''], 'rejected choice'],
    ['tags', [123], 'rejected type'],
    ['tags', ['long'], 'rejected length'],
]);

it('preserves declared protected extras from each original owned path during save and reorder', function ($multiple) {
    Storage::fake('public');
    $a = ['path' => 'old/a.png', 'extra' => ['caption' => 'approved A', 'locked' => 'locked A', 'editable' => 'old', 'unknown' => 'discard']];
    $b = ['path' => 'old/b.png', 'extra' => ['caption' => 'approved B', 'locked' => 'locked B', 'editable' => 'old']];
    $record = uploadRecord($multiple ? [$a, $b] : $a);
    $form = Form::make(UploadRecord::class)->id('protected')->urls(fn () => ['edit' => '/images/1']);
    $method = $multiple ? 'multipleImage' : 'image';
    $form->$method('images')->extra(function ($extra) {
        $extra->text('caption')->readOnly();
        $extra->text('locked')->disabled();
        $extra->text('editable');
    });
    $submitted = [];
    foreach ($multiple ? [$b, $a] : [$a] as $item) {
        $submitted[] = ['path' => $item['path'], 'extra' => ['caption' => 'forged', 'locked' => 'forged', 'editable' => 'changed', 'unknown' => 'forged']];
    }
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['images' => $multiple ? $submitted : $submitted[0]]]));
    $stored = $record->fresh()->images;
    foreach ($multiple ? $stored : [$stored] as $item) {
        $suffix = $item['path'] === 'old/a.png' ? 'A' : 'B';
        $expected = ['caption' => 'approved '.$suffix, 'locked' => 'locked '.$suffix, 'editable' => 'changed'];
        if ($suffix === 'A') {
            $expected['unknown'] = 'discard';
        }
        expect($item['extra'])->toEqual($expected);
    }
    if ($multiple) {
        expect(array_column($stored, 'path'))->toBe(['old/b.png', 'old/a.png']);
    }
})->with([true, false]);

it('applies extra range bound rules and field messages at their complete path', function ($type, $start, $end) {
    Storage::fake('local');
    Storage::fake('public');
    $this->actingAs(brumeUser(), 'brume');
    $record = uploadRecord();
    $form = Form::make(UploadRecord::class)->id('extra-range')->forRecord($record)->urls(fn () => ['edit' => '/images/1']);
    $field = $form->image('images')->extra(fn ($extra) => $extra->$type('period', 'Period label', 'start', 'end')->messages(['after_or_equal' => ':attribute rejected order']));
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'images', $field);
    try {
        (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['images' => ['token' => $upload['token'], 'extra' => ['period' => ['start' => $start, 'end' => $end]]]]]));
        test()->fail('Expected invalid range');
    } catch (ValidationException $e) {
        expect($e->errors()['images.extra.period.end'][0])->toBe('Period label rejected order');
    }
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    ['dateRange', '2026-11-02', '2026-11-01'],
    ['datetimeRange', '2026-11-02T10:00', '2026-11-01T10:00'],
]);

it('accepts declared blank choices and never trusts protected metadata on a new token', function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->actingAs(brumeUser(), 'brume');
    $record = uploadRecord();
    $form = Form::make(UploadRecord::class)->id('extra-blank')->forRecord($record)->urls(fn () => ['edit' => '/images/1']);
    $field = $form->image('images')->extra(function ($extra) {
        $extra->multipleSelect('roles')->options(['' => 'Blank', 'a' => 'A']);
        $extra->text('protected')->readOnly();
        $extra->text('disabled')->disabled();
    });
    $upload = app(TempUploads::class)->store(UploadedFile::fake()->image('a.png'), $form, 'images', $field);
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['images' => ['token' => $upload['token'], 'extra' => ['roles' => [''], 'protected' => 'forged', 'disabled' => 'forged', 'unknown' => 'forged']]]]));
    expect($record->fresh()->images['extra'])->toEqual(['roles' => ['']]);
});
