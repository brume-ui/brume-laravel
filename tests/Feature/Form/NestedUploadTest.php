<?php

use Brume\Form\ConditionPath;
use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Form\Relations\RelationPlanner;
use Brume\Form\UploadArchiver;
use Brume\Options\OptionsController;
use Brume\Options\OptionSource;
use Brume\Resource\ResourceDeletion;
use Brume\Uploads\TempUpload;
use Brume\Uploads\TempUploads;
use Brume\Uploads\UploadController;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class NestedUploadRecord extends Model
{
    protected $table = 'nested_upload_records';

    protected $connection = 'nested_uploads';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['data' => 'array'];

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function owner()
    {
        return $this->belongsTo(self::class, 'owner_id');
    }

    public function products()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function softChildren()
    {
        return $this->hasMany(SoftNestedUploadRecord::class, 'parent_id');
    }

    public function parts()
    {
        return $this->belongsToMany(self::class, 'nested_upload_parts', 'root_id', 'product_id')->withPivot(['file', 'data']);
    }
}

class SoftNestedUploadRecord extends NestedUploadRecord
{
    use SoftDeletes;
}

beforeEach(function () {
    config(['database.connections.nested_uploads' => getenv('BRUME_TEST_DB') === 'mysql'
        ? [...config('database.connections.mysql'), 'prefix' => 'nested_physical_']
        : ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    config(['brume.uploads.connections' => [config('database.default'), 'nested_uploads']]);
    DB::connection('nested_uploads')->setTransactionManager(new DatabaseTransactionsManager);
    DB::usingConnection('nested_uploads', function () {
        (require __DIR__.'/../../../database/migrations/2026_10_29_000000_create_brume_uploads_table.php')->up();
        (require __DIR__.'/../../../database/migrations/2026_10_31_000001_add_row_context_to_brume_uploads_table.php')->up();
    });
    Schema::connection('nested_uploads')->create('nested_upload_records', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->unsignedBigInteger('owner_id')->nullable();
        $table->string('file')->nullable();
        $table->string('scope')->nullable();
        $table->json('data')->nullable();
        $table->softDeletes();
    });
    Schema::connection('nested_uploads')->create('nested_upload_parts', function ($table) {
        $table->unsignedBigInteger('root_id');
        $table->unsignedBigInteger('product_id');
        $table->string('file')->nullable();
        $table->json('data')->nullable();
        $table->primary(['root_id', 'product_id']);
    });
    Storage::fake('local');
    Storage::fake('public');
    $this->actingAs(brumeUser(), 'brume');
});

function nestedUploadForm(?Model $root = null): Form
{
    $form = Form::make(NestedUploadRecord::class)->id('nested-files')->forRecord($root)->urls(fn ($r) => ['edit' => '/nested/'.$r->getKey()]);
    $form->file('file');
    $form->repeater('children', 'Children', function ($row) {
        $row->file('file');
        $row->repeater('children', 'Children', fn ($child) => $child->file('file'))->relation('children');
        $row->repeater('data', 'Data', fn ($child) => $child->file('file'));
    })->relation('children');
    $form->repeater('data', 'Data', function ($row) {
        $row->file('file');
        $row->repeater('rows', 'Rows', fn ($child) => $child->file('file'));
    });

    return $form;
}
function nestedUploadRow(Model $row, array $values = []): array
{
    return ['_brume' => ['key' => 'record:'.$row->getKey(), 'id' => (string) $row->getKey()]] + $values;
}
function nestedUploadToken(Form $form, string $path, array $context): array
{
    $request = Request::create('/', 'POST', ['field' => $path, 'rowContext' => json_encode($context)], [], ['file' => UploadedFile::fake()->createWithContent('a.txt', 'nested upload')]);

    return app(UploadController::class)->store($form, $request)->getData(true);
}
function nestedUploadSave(Form $form, Model $root, array $values): void
{
    (new FormSaver)->save($form, $root, Request::create('/', 'PUT', ['values' => $values]));
}

it('nested upload follows a stable relation row after reorder', function () {
    $root = NestedUploadRecord::create();
    $a = $root->children()->create();
    $b = $root->children()->create();
    $form = nestedUploadForm($root);
    $context = [['path' => 'children', 'key' => 'record:'.$a->id, 'id' => (string) $a->id]];
    $token = nestedUploadToken($form, 'children.0.file', $context);
    nestedUploadSave($form, $root, ['children' => [nestedUploadRow($b), nestedUploadRow($a, ['file' => ['token' => $token['token']]])]]);
    expect($a->fresh()->file)->toStartWith('brume/nested-files/')->and($b->fresh()->file)->toBeNull();
    Storage::disk('public')->assertExists($a->fresh()->file);
    expect(TempUpload::on('nested_uploads')->where('token', $token['token'])->exists())->toBeFalse();
});

it('nested upload retains raw JSON extras and omitted files through reorder', function () {
    $root = NestedUploadRecord::create(['data' => [['_brume' => ['key' => 'a'], 'file' => 'old/a.txt', 'private' => ['counter' => 7]], ['_brume' => ['key' => 'b'], 'file' => 'old/b.txt']]]);
    Storage::disk('public')->put('old/a.txt', 'a');
    Storage::disk('public')->put('old/b.txt', 'b');
    nestedUploadSave(nestedUploadForm($root), $root, ['data' => [['_brume' => ['key' => 'b']], ['_brume' => ['key' => 'a'], 'file' => ['path' => 'old/a.txt']]]]);
    $data = $root->fresh()->data;
    // JSON object property order is driver-defined; row order and every typed value are exact.
    foreach ($data as &$storedRow) {
        ksort($storedRow);
    }
    unset($storedRow);
    expect($data)->toBe([['_brume' => ['key' => 'b'], 'file' => 'old/b.txt'], ['_brume' => ['key' => 'a'], 'file' => 'old/a.txt', 'private' => ['counter' => 7]]]);
    Storage::disk('public')->assertExists(['old/a.txt', 'old/b.txt']);
});

it('nested parent removal cleans all descendant files only after outer commit', function () {
    $root = NestedUploadRecord::create();
    $child = $root->children()->create(['file' => 'old/child.txt', 'data' => [['_brume' => ['key' => 'json'], 'file' => 'old/json.txt']]]);
    $child->children()->create(['file' => 'old/grandchild.txt']);
    foreach (['old/child.txt', 'old/json.txt', 'old/grandchild.txt'] as $path) {
        Storage::disk('public')->put($path, 'old');
    }
    $root->getConnection()->beginTransaction();
    nestedUploadSave(nestedUploadForm($root), $root, ['children' => []]);
    expect(Storage::disk('public')->allFiles())->toHaveCount(3);
    $root->getConnection()->commit();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('nested removed JSON row cleans its file while retaining an omitted sibling container', function () {
    $root = NestedUploadRecord::create(['data' => [['_brume' => ['key' => 'a'], 'file' => 'old/a.txt'], ['_brume' => ['key' => 'b'], 'rows' => [['_brume' => ['key' => 'inner'], 'file' => 'old/b.txt']]]]]);
    Storage::disk('public')->put('old/a.txt', 'a');
    Storage::disk('public')->put('old/b.txt', 'b');
    nestedUploadSave(nestedUploadForm($root), $root, ['data' => [['_brume' => ['key' => 'b']]]]);
    Storage::disk('public')->assertMissing('old/a.txt');
    Storage::disk('public')->assertExists('old/b.txt');
});

afterEach(function () {
    $connection = DB::connection('nested_uploads');
    if ($connection->transactionLevel() > 0) {
        $connection->rollBack(0);
    }
    foreach (['nested_upload_parts', 'nested_upload_records', 'brume_uploads'] as $table) {
        Schema::connection('nested_uploads')->dropIfExists($table);
    }
    DB::purge('nested_uploads');
});

it('nested token rejects a different row root user and duplicate claims before any copy', function ($attack) {
    $root = NestedUploadRecord::create();
    $a = $root->children()->create();
    $b = $root->children()->create();
    $form = nestedUploadForm($root);
    $context = [['path' => 'children', 'key' => 'record:'.$a->id, 'id' => (string) $a->id]];
    $token = nestedUploadToken($form, 'children.0.file', $context);
    $values = ['children' => [nestedUploadRow($a, ['file' => ['token' => $token['token']]]), nestedUploadRow($b)]];
    if ($attack === 'row') {
        $values = ['children' => [nestedUploadRow($b, ['file' => ['token' => $token['token']]])]];
    }
    if ($attack === 'root') {
        $root = NestedUploadRecord::create();
        $form = nestedUploadForm($root);
        $values = ['children' => [['_brume' => ['key' => 'record:'.$a->id], 'file' => ['token' => $token['token']]]]];
    }
    if ($attack === 'user') {
        $this->actingAs(brumeUser(['username' => 'other']), 'brume');
    }
    if ($attack === 'duplicate') {
        $values['children'][1]['file'] = ['token' => $token['token']];
    }
    if ($attack === 'duplicate-key') {
        $values['children'][1]['_brume']['key'] = 'record:'.$a->id;
    }
    expect(fn () => nestedUploadSave($form, $root, $values))->toThrow(ValidationException::class);
    expect(Storage::disk('public')->allFiles())->toBe([])->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    expect(TempUpload::on('nested_uploads')->where('token', $token['token'])->exists())->toBeTrue();
    expect($a->fresh()->file)->toBeNull()->and($b->fresh()->file)->toBeNull();
})->with(['row', 'root', 'user', 'duplicate', 'duplicate-key']);

it('nested forged last path preflights before an earlier valid token or any model save', function () {
    $root = NestedUploadRecord::create();
    $a = $root->children()->create(['file' => 'old/a.txt']);
    $b = $root->children()->create(['file' => 'old/b.txt']);
    Storage::disk('public')->put('old/a.txt', 'a');
    Storage::disk('public')->put('old/b.txt', 'b');
    $form = nestedUploadForm($root);
    $token = nestedUploadToken($form, 'children.0.file', [['path' => 'children', 'key' => 'record:'.$a->id, 'id' => (string) $a->id]]);
    $saves = 0;
    NestedUploadRecord::saving(function () use (&$saves) {
        $saves++;
    });
    try {
        expect(fn () => nestedUploadSave($form, $root, ['children' => [nestedUploadRow($a, ['file' => ['token' => $token['token']]]), nestedUploadRow($b, ['file' => ['path' => 'other/foreign.txt']])]]))->toThrow(ValidationException::class);
        expect($saves)->toBe(0)->and(Storage::disk('public')->allFiles())->toBe(['old/a.txt', 'old/b.txt']);
    } finally {
        NestedUploadRecord::flushEventListeners();
    }
});

it('nested rollback removes only new finals and permits retry with unchanged temporary content', function () {
    $root = NestedUploadRecord::create();
    $child = $root->children()->create(['file' => 'old/a.txt']);
    Storage::disk('public')->put('old/a.txt', 'a');
    $form = nestedUploadForm($root);
    $token = nestedUploadToken($form, 'children.0.file', [['path' => 'children', 'key' => 'record:'.$child->id, 'id' => (string) $child->id]]);
    $values = ['children' => [nestedUploadRow($child, ['file' => ['token' => $token['token']]])]];
    $connection = $root->getConnection();
    $connection->beginTransaction();
    nestedUploadSave($form, $root, $values);
    $final = $child->fresh()->file;
    Storage::disk('public')->assertExists(['old/a.txt', $final]);
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
    $connection->rollBack();
    expect($child->fresh()->file)->toBe('old/a.txt');
    Storage::disk('public')->assertMissing($final);
    expect(TempUpload::on('nested_uploads')->where('token', $token['token'])->exists())->toBeTrue();
    nestedUploadSave(nestedUploadForm($root->fresh()), $root->fresh(), $values);
    Storage::disk('public')->assertMissing('old/a.txt');
    expect(Storage::disk('local')->allFiles())->toBe([]);
    Storage::disk('public')->assertExists($child->fresh()->file);
});

it('nested JSON upload at arbitrary depth composes archived leaves without losing raw siblings', function () {
    $root = NestedUploadRecord::create(['data' => [['_brume' => ['key' => 'outer'], 'private' => (object) [], 'rows' => [['_brume' => ['key' => 'inner'], 'file' => 'old/file.txt', 'unknown' => ['v' => 8]]]]]]);
    Storage::disk('public')->put('old/file.txt', 'old');
    $form = nestedUploadForm($root);
    $context = [['path' => 'data', 'key' => 'outer'], ['path' => 'data.*.rows', 'key' => 'inner']];
    $token = nestedUploadToken($form, 'data.0.rows.0.file', $context);
    nestedUploadSave($form, $root, ['data' => [['_brume' => ['key' => 'outer'], 'rows' => [['_brume' => ['key' => 'inner'], 'file' => ['token' => $token['token']]]]]]]);
    $data = json_decode($root->fresh()->getRawOriginal('data'));
    expect($data[0]->private)->toBeInstanceOf(stdClass::class)->and($data[0]->rows[0]->unknown->v)->toBe(8);
    Storage::disk('public')->assertExists($data[0]->rows[0]->file);
    Storage::disk('public')->assertMissing('old/file.txt');
});

it('nested readonly upload retains raw representation and readonly metadata', function () {
    $root = NestedUploadRecord::create(['data' => [['_brume' => ['key' => 'a'], 'file' => 'old/a.txt']]]);
    $form = nestedUploadForm($root);
    $form->fields()['data']->rowForm()->fields()['file']->readOnly();
    nestedUploadSave($form, $root, ['data' => [['_brume' => ['key' => 'a'], 'file' => ['path' => 'forged.txt']]]]);
    expect($root->fresh()->data[0]['file'])->toBe('old/a.txt');
});

function nestedPickerForm(Model $root, string $mode): Form
{
    $form = Form::make(NestedUploadRecord::class)->id('nested-picker')->forRecord($root)->urls(fn ($r) => ['edit' => '/nested/'.$r->id]);
    $form->relationPicker($mode, 'Source', OptionSource::make(NestedUploadRecord::class)->label('file'))->relation($mode)->multiple($mode !== 'owner')->extra(function ($row) {
        $row->file('file');
        $row->repeater('children', 'Children', fn ($child) => $child->file('file'))->relation('children');
    });

    return $form;
}

it('nested newly associated source uploads retain null own callbacks and actual descendant storage', function ($mode) {
    $root = NestedUploadRecord::create();
    $selected = NestedUploadRecord::create(['file' => 'old/source.txt']);
    $child = $selected->children()->create(['file' => 'old/child.txt']);
    Storage::disk('public')->put('old/source.txt', 'source');
    Storage::disk('public')->put('old/child.txt', 'child');
    $form = nestedPickerForm($root, $mode);
    $form->fields()[$mode]->rowForm()->fields()['file']->readOnly(fn ($callback) => $callback !== null);
    $prefix = $mode === 'owner' ? 'owner' : 'products.0';
    $template = $mode === 'owner' ? 'owner.children' : 'products.*.children';
    $context = [['path' => $mode, 'key' => (string) $selected->id, 'id' => (string) $selected->id], ['path' => $template, 'key' => 'record:'.$child->id, 'id' => (string) $child->id]];
    $token = nestedUploadToken($form, $prefix.'.children.0.file', $context);
    $value = ['id' => (string) $selected->id, 'file' => ['path' => 'old/source.txt'], 'children' => [nestedUploadRow($child, ['file' => ['token' => $token['token']]])]];
    nestedUploadSave($form, $root, [$mode => $mode === 'owner' ? $value : [$value]]);
    expect($selected->fresh()->file)->toBe('old/source.txt')->and($child->fresh()->file)->toStartWith('brume/nested-picker/');
    Storage::disk('public')->assertMissing('old/child.txt');
    Storage::disk('public')->assertExists('old/source.txt');
})->with(['owner', 'products']);

it('nested upload verifies new picker source dependencies before accepting a file', function ($allowed) {
    $root = NestedUploadRecord::create();
    $selected = NestedUploadRecord::create(['file' => 'tenant-a']);
    $form = nestedPickerForm($root, 'owner');
    $form->text('scope');
    $source = OptionSource::make(fn ($context) => NestedUploadRecord::where('file', $context->dependencies['scope']))->label('file');
    $form->relationPicker('selected', 'Selected', $source)->relation('owner')->multiple(false)->remote($source, ['scope' => ConditionPath::root('scope')])->extra(fn ($row) => $row->file('file'));
    $request = Request::create('/', 'POST', ['field' => 'selected.file', 'rowContext' => json_encode([['path' => 'selected', 'key' => (string) $selected->id, 'id' => (string) $selected->id]]), 'ancestorSources' => json_encode([['path' => 'selected', 'rowContext' => [], 'depends' => ['scope' => $allowed ? 'tenant-a' : 'tenant-b']]])], [], ['file' => UploadedFile::fake()->createWithContent('a.txt', 'upload')]);
    if ($allowed) {
        expect(app(UploadController::class)->store($form, $request)->getStatusCode())->toBe(200);
    } else {
        expect(fn () => app(UploadController::class)->store($form, $request))->toThrow(ValidationException::class);
    }
    expect(Storage::disk('local')->allFiles())->toHaveCount($allowed ? 1 : 0);
})->with([true, false]);

it('nested hook added source supplements original raw descendant inventory before replacing files', function ($mode) {
    $root = NestedUploadRecord::create();
    $first = NestedUploadRecord::create(['file' => 'old/first.txt']);
    $second = NestedUploadRecord::create(['file' => 'old/second.txt']);
    $removed = $second->children()->create(['file' => 'old/removed.txt']);
    foreach (['old/first.txt', 'old/second.txt', 'old/removed.txt'] as $path) {
        Storage::disk('public')->put($path, 'old');
    }
    $form = nestedPickerForm($root, $mode);
    $form->saving(function ($model, &$values) use ($mode, $second) {
        $selected = ['id' => (string) $second->id, 'file' => ['path' => 'old/second.txt'], 'children' => []];
        $values[$mode] = $mode === 'owner' ? $selected : [$selected];
    });
    $selected = ['id' => (string) $first->id];
    nestedUploadSave($form, $root, [$mode => $mode === 'owner' ? $selected : [$selected]]);
    expect($second->fresh()->file)->toBe('old/second.txt')->and($removed->fresh())->toBeNull();
    Storage::disk('public')->assertMissing('old/removed.txt');
    Storage::disk('public')->assertExists(['old/first.txt', 'old/second.txt']);
})->with(['owner', 'products']);

it('nested pivot uploads bind source identity but archive pivot storage and clean on detach', function () {
    $root = NestedUploadRecord::create();
    $selected = NestedUploadRecord::create(['file' => 'source/untouched.txt']);
    $root->parts()->attach($selected->id, ['file' => 'old/pivot.txt']);
    Storage::disk('public')->put('old/pivot.txt', 'old');
    Storage::disk('public')->put('source/untouched.txt', 'source');
    $form = Form::make(NestedUploadRecord::class)->id('pivot-upload')->forRecord($root)->urls(fn () => ['edit' => '/nested']);
    $form->relationPicker('parts', 'Parts', OptionSource::make(NestedUploadRecord::class)->label('file'))->relation('parts')->extra(fn ($row) => $row->file('file'));
    $token = nestedUploadToken($form, 'parts.0.file', [['path' => 'parts', 'key' => (string) $selected->id, 'id' => (string) $selected->id]]);
    nestedUploadSave($form, $root, ['parts' => [['id' => (string) $selected->id, 'file' => ['token' => $token['token']]]]]);
    $path = $root->fresh()->parts()->first()->pivot->file;
    Storage::disk('public')->assertExists($path);
    Storage::disk('public')->assertMissing('old/pivot.txt');
    expect($selected->fresh()->file)->toBe('source/untouched.txt');
    nestedUploadSave($form, $root, ['parts' => []]);
    Storage::disk('public')->assertMissing($path);
    Storage::disk('public')->assertExists('source/untouched.txt');
});

it('nested options resolver keeps newly selected source storage separate from null callbacks', function ($mode) {
    $root = NestedUploadRecord::create();
    $selected = NestedUploadRecord::create();
    $child = $selected->children()->create(['file' => 'child']);
    $form = nestedPickerForm($root, $mode);
    $form->fields()[$mode]->rowForm()->fields()['children']->rowForm()->select('choice')->remote(OptionSource::make(NestedUploadRecord::class)->label('file'));
    $template = $mode === 'owner' ? 'owner.children.*.choice' : 'products.*.children.*.choice';
    $context = [['path' => $mode, 'key' => (string) $selected->id, 'id' => (string) $selected->id], ['path' => $mode === 'owner' ? 'owner.children' : 'products.*.children', 'key' => 'record:'.$child->id, 'id' => (string) $child->id]];
    $response = (new OptionsController)->respond($form, Request::create('/', 'GET', ['field' => $template, 'rowContext' => json_encode($context)]));
    expect($response->getStatusCode())->toBe(200);
})->with(['owner', 'products']);

it('nested soft child removal and source detach retain source owned files', function ($mode) {
    $root = NestedUploadRecord::create();
    $child = $root->children()->create(['file' => 'old/retained.txt']);
    Storage::disk('public')->put('old/retained.txt', 'old');
    if ($mode === 'soft') {
        $form = Form::make(NestedUploadRecord::class)->id('soft-upload')->forRecord($root)->urls(fn () => ['edit' => '/nested']);
        $form->repeater('softChildren', 'Children', fn ($row) => $row->file('file'))->relation('softChildren');
        nestedUploadSave($form, $root, ['softChildren' => []]);
        expect(SoftNestedUploadRecord::withTrashed()->find($child->id)->trashed())->toBeTrue();
    } else {
        if ($mode === 'owner') {
            $root->owner_id = $child->id;
            $root->save();
        }
        nestedUploadSave(nestedPickerForm($root, $mode), $root, [$mode => $mode === 'owner' ? null : []]);
        expect($child->fresh())->not->toBeNull();
    }
    Storage::disk('public')->assertExists('old/retained.txt');
})->with(['soft', 'owner', 'products']);

it('nested hard delete inventories owned child files but retains attached source files', function ($mode) {
    $root = NestedUploadRecord::create();
    $child = $root->children()->create(['file' => 'old/retained.txt']);
    if ($mode === 'owner') {
        $root->owner_id = $child->id;
        $root->save();
    }
    Storage::disk('public')->put('old/retained.txt', 'old');
    $form = $mode === 'children' ? nestedUploadForm($root) : nestedPickerForm($root, $mode);
    (new ResourceDeletion)->run(collect([$root]), fn () => $form);
    expect(Storage::disk('public')->exists('old/retained.txt'))->toBe($mode !== 'children');
})->with(['owner', 'products', 'children']);

it('nested newly selected owner readonly dependencies use storage with null evaluation', function ($mode, $collection) {
    $root = NestedUploadRecord::create();
    $selected = NestedUploadRecord::create(['scope' => 'stored', 'data' => [['_brume' => ['key' => 'a'], 'file' => 'stored-json']]]);
    $form = nestedPickerForm($root, $mode);
    $extra = $form->fields()[$mode]->rowForm();
    $extra->text('scope')->readOnly(fn ($callback) => $callback === null);
    $extra->repeater('data', 'Data', fn ($row) => $row->text('file'))->readOnly(fn ($callback) => $callback === null);
    $seen = null;
    $extra->select('choice')->remote(OptionSource::make(function ($context) use (&$seen) {
        $seen = $context->dependencies['authority'];

        return NestedUploadRecord::query();
    })->label('file'), ['authority' => ConditionPath::row($collection ? 'data' : 'scope')]);
    (new OptionsController)->respond($form, Request::create('/', 'GET', [
        'field' => $mode.($mode === 'owner' ? '.' : '.*.').'choice', 'rowContext' => json_encode([['path' => $mode, 'key' => (string) $selected->id, 'id' => (string) $selected->id]]),
        'depends' => json_encode(['authority' => $collection ? [] : 'forged']),
    ]));
    expect($seen)->toBe($collection ? [['_brume' => ['key' => 'a'], 'file' => 'stored-json']] : 'stored');
})->with([['owner', false], ['owner', true], ['products', false], ['products', true]]);

it('nested additive schema upgrade preserves legacy root claims without granting nested authority', function () {
    $migration = require __DIR__.'/../../../database/migrations/2026_10_31_000001_add_row_context_to_brume_uploads_table.php';
    DB::usingConnection('nested_uploads', function () use ($migration) {
        $migration->down();
    });
    $root = NestedUploadRecord::create();
    $token = str_repeat('a', 40);
    DB::connection('nested_uploads')->table('brume_uploads')->insert([
        'token' => $token, 'form' => 'nested-files', 'field' => 'file', 'user_id' => (string) auth('brume')->id(), 'guard' => 'brume',
        'record' => (string) $root->id, 'disk' => 'local', 'path' => 'brume-tmp/a.txt', 'original_name' => 'a.txt', 'mime' => 'text/plain', 'size' => 1,
        'created_at' => now(), 'expires_at' => now()->addHour(),
    ]);
    DB::usingConnection('nested_uploads', function () use ($migration) {
        $migration->up();
    });
    $uploads = app(TempUploads::class);
    expect($uploads->claim($token, nestedUploadForm($root), 'file'))->not->toBeNull();
    expect($uploads->claim($token, nestedUploadForm($root), 'file', [['path' => 'data', 'key' => 'a']]))->toBeNull();
    Storage::disk('local')->put('brume-tmp/a.txt', 'a');
    nestedUploadSave(nestedUploadForm($root), $root, ['file' => ['token' => $token]]);
    Storage::disk('public')->assertExists($root->fresh()->file);
});

it('nested options typed collection readers retain nullable evaluation and JSON global root', function ($mode) {
    $root = NestedUploadRecord::create(['file' => 'root']);
    $selected = NestedUploadRecord::create(['data' => [['_brume' => ['key' => 'a'], 'file' => 'stored']]]);
    $form = nestedPickerForm($root, $mode);
    $extra = $form->fields()[$mode]->rowForm();
    $calls = 0;
    $extra->repeater('data', 'Data', fn ($row) => $row->text('file'))->readOnly()->value(function (NestedUploadRecord $context) use (&$calls) {
        $calls++;

        return $context->data;
    });
    $seen = null;
    $extra->select('choice')->remote(OptionSource::make(function ($context) use (&$seen) {
        $seen = $context->dependencies['authority'];

        return NestedUploadRecord::query();
    })->label('file'), ['authority' => ConditionPath::row('data')]);
    (new OptionsController)->respond($form, Request::create('/', 'GET', [
        'field' => $mode.($mode === 'owner' ? '.' : '.*.').'choice',
        'rowContext' => json_encode([['path' => $mode, 'key' => (string) $selected->id, 'id' => (string) $selected->id]]), 'depends' => json_encode(['authority' => []]),
    ]));
    expect($calls)->toBe(0)->and($seen)->toBe([]);
    $callbacks = [];
    $extra->fields()['data']->rowForm()->file('upload')->readOnly(function (?NestedUploadRecord $context) use (&$callbacks) {
        $callbacks[] = $context?->id;

        return false;
    });
    $extra->fields()['data']->readOnly(false);
    nestedUploadToken($form, $mode.($mode === 'owner' ? '.' : '.0.').'data.0.upload', [
        ['path' => $mode, 'key' => (string) $selected->id, 'id' => (string) $selected->id],
        ['path' => $mode.($mode === 'owner' ? '.' : '.*.').'data', 'key' => 'new'],
    ]);
    expect($callbacks)->not->toBeEmpty();
    expect(array_unique($callbacks))->toBe([$root->id]);
    expect($calls)->toBe(0);
})->with(['owner', 'products']);

it('nested hard parent removal stops cleanup at source owned association boundaries', function ($mode) {
    $root = NestedUploadRecord::create();
    $child = $root->children()->create(['file' => 'old/child.txt']);
    $source = NestedUploadRecord::create(['file' => 'old/source.txt']);
    if ($mode === 'owner') {
        $child->owner_id = $source->id;
        $child->save();
    } else {
        $source->parent_id = $child->id;
        $source->save();
    }
    $source->children()->create(['file' => 'old/source-descendant.txt']);
    foreach (['old/child.txt', 'old/source.txt', 'old/source-descendant.txt'] as $path) {
        Storage::disk('public')->put($path, 'old');
    }
    $form = Form::make(NestedUploadRecord::class)->id('nested-source-removal')->forRecord($root)->urls(fn () => ['edit' => '/nested']);
    $form->repeater('children', 'Children', function ($row) use ($mode) {
        $row->file('file');
        $row->relationPicker($mode, 'Source', OptionSource::make(NestedUploadRecord::class)->label('file'))->relation($mode)->multiple($mode !== 'owner')->extra(function ($extra) {
            $extra->file('file');
            $extra->repeater('children', 'Children', fn ($nested) => $nested->file('file'))->relation('children');
        });
    })->relation('children');
    nestedUploadSave($form, $root, ['children' => []]);
    Storage::disk('public')->assertMissing('old/child.txt');
    Storage::disk('public')->assertExists(['old/source.txt', 'old/source-descendant.txt']);
})->with(['owner', 'products']);

it('nested token canonical context ignores JSON object property order but preserves ordered row identity', function () {
    $root = NestedUploadRecord::create();
    $child = $root->children()->create();
    $form = nestedUploadForm($root);
    $context = [['path' => 'children', 'key' => 'record:'.$child->id, 'id' => (string) $child->id]];
    $token = nestedUploadToken($form, 'children.0.file', $context);
    TempUpload::on('nested_uploads')->where('token', $token['token'])->update(['row_context' => json_encode([['id' => (string) $child->id, 'key' => 'record:'.$child->id, 'path' => 'children']])]);
    nestedUploadSave($form, $root, ['children' => [nestedUploadRow($child, ['file' => ['token' => $token['token']]])]]);
    Storage::disk('public')->assertExists($child->fresh()->file);
});

it('nested archive plan scope restores after a host override exception or reentrant archive', function ($reentrant) {
    $a = NestedUploadRecord::create(['file' => 'old/a.txt']);
    $b = NestedUploadRecord::create(['file' => 'old/b.txt']);
    Storage::disk('public')->put('old/a.txt', 'a');
    Storage::disk('public')->put('old/b.txt', 'b');
    $formA = nestedUploadForm($a);
    $formB = nestedUploadForm($b);
    $archiver = new class extends UploadArchiver
    {
        public ?Closure $next = null;

        public function archive(Form $form, Model $record, array &$values): void
        {
            $next = $this->next;
            $this->next = null;
            if ($next !== null) {
                $next();
            }
            parent::archive($form, $record, $values);
        }
    };
    $a->getConnection()->transaction(function () use ($a, $b, $formA, $formB, $archiver, $reentrant) {
        $planner = new RelationPlanner;
        $planA = $planner->plan($formA, $a, ['file' => ['path' => 'old/a.txt']], $a);
        $planB = $planner->plan($formB, $b, ['file' => ['path' => 'old/b.txt']], $b);
        $valuesA = $planA->values();
        $archiver->next = $reentrant
            ? function () use ($archiver, $formB, $b, $planB) {
                $valuesB = $planB->values();
                $archiver->archivePlan($formB, $b, $valuesB, $planB);
                expect($valuesB['file'])->toBe('old/b.txt');
            }
        : fn () => throw new RuntimeException('host override failed');
        if ($reentrant) {
            $archiver->archivePlan($formA, $a, $valuesA, $planA);
            expect($valuesA['file'])->toBe('old/a.txt');
        } else {
            expect(fn () => $archiver->archivePlan($formA, $a, $valuesA, $planA))->toThrow(RuntimeException::class, 'host override failed');
        }
        // A standalone legacy call must not inherit either scoped graph's ownership.
        $standalone = ['file' => ['path' => 'old/b.txt']];
        $archiver->archive($formB, $b, $standalone);
        expect($standalone['file'])->toBe('old/b.txt');
    });
    Storage::disk('public')->assertExists(['old/a.txt', 'old/b.txt']);
})->with([false, true]);
