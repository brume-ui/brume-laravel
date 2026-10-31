<?php

use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Form\UploadArchiver;
use Brume\Grid\Actions\Feedback;
use Brume\Grid\Grid;
use Brume\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

class FormSaveRecord extends Model
{
    protected $table = 'form_save_records';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['meta' => 'array'];
}

it('initializes editing once for rendering and again with the locked root for save', function () {
    $record = brumeUser();
    $count = 0;
    $form = Form::make(User::class)->forRecord($record)->submitTo('put', '/users/1')->urls(fn () => ['edit' => '/users/1']);
    $form->editing(function ($r, $f) use (&$count, $record) {
        expect($r)->toBe($record);
        $count++;
    });
    $form->toSchema(Request::create('/'));
    $form->toSchema(Request::create('/'));
    expect($count)->toBe(1);
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => (object) []]));
    expect($count)->toBe(2);
});

it('preserves embedded siblings and fixed point strings and maps date ranges', function () {
    $record = FormSaveRecord::create(['meta' => ['color' => 'old', 'locked' => 'keep', 'unknown' => 'keep']]);
    $form = Form::make(FormSaveRecord::class)->urls(fn () => ['edit' => '/records/1']);
    $form->embeds('meta', 'Meta', function ($f) {
        $f->text('color');
        $f->text('locked')->disabled();
    });
    $form->datetimeRange('period', 'Period', 'starts_at', 'ends_at');
    $form->decimal('amount')->decimals(2);
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['meta' => ['color' => 'red', 'locked' => 'forged', 'unknown' => 'forged'], 'period' => ['start' => '2026-10-31T12:30', 'end' => '2026-11-01T14:00'], 'amount' => '99999999999999.10']]));
    $fresh = $record->fresh();
    // JSON object member order is not preserved by every database.
    $meta = $fresh->meta;
    ksort($meta);
    expect($meta)->toBe(['color' => 'red', 'locked' => 'keep', 'unknown' => 'keep'])->and($fresh->starts_at)->toBe('2026-10-31 12:30:00')->and($fresh->ends_at)->toBe('2026-11-01 14:00:00')->and($fresh->amount)->toBe('99999999999999.10');
});

it('archives rewritten values without invoking unchanged custom writers twice', function () {
    $record = FormSaveRecord::create();
    $form = Form::make(FormSaveRecord::class)->urls(fn () => ['edit' => '/records/1']);
    $count = 0;
    $form->text('amount')->saveUsing(function ($r, $v) use (&$count) {
        $count++;
        $r->amount = $v;
    });
    $form->file('file');
    app()->instance(UploadArchiver::class, new class extends UploadArchiver
    {
        public function archive(Form $form, Model $record, array &$values): void
        {
            $values['file'] = 'final/file.pdf';
        }
    });
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['amount' => '10.00', 'file' => ['token' => str_repeat('a', 40)]]]));
    expect($record->fresh()->file)->toBe('final/file.pdf')->and($count)->toBe(1);
});

it('rejects missing required edit urls before writes', function () {
    $record = brumeUser();
    $form = Form::make(User::class);
    $form->text('name');
    expect(fn () => (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['name' => 'Changed']])))->toThrow(LogicException::class);
    expect($record->fresh()->name)->toBe('管理員');
});

it('rejects unsafe result urls and rolls back the transaction', function () {
    app()->detectEnvironment(fn () => 'local');
    $record = brumeUser();
    $form = Form::make(User::class)->urls(fn () => ['edit' => 'javascript:bad']);
    $form->text('name');
    expect(fn () => (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['name' => 'Changed']])))->toThrow(InvalidArgumentException::class);
    expect($record->fresh()->name)->toBe('管理員');
});

it('requires an embedded json cast and never assigns an undeclared object', function () {
    $record = brumeUser();
    $form = Form::make(User::class)->urls(fn () => ['edit' => '/users/1']);
    $form->embeds('meta', 'Meta', fn ($f) => $f->text('color'));
    expect(fn () => (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['meta' => ['color' => 'red']]])))->toThrow(LogicException::class);
});

it('honors hashed casts and never writes empty passwords through a custom writer', function () {
    $record = brumeUser();
    $record->mergeCasts(['password' => 'hashed']);
    $form = Form::make(User::class)->urls(fn () => ['edit' => '/users/1']);
    $form->password('password');
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['password' => 'new-secret']]));
    expect(Hash::check('new-secret', $record->fresh()->password))->toBeTrue();
    $form->fields()['password']->saveUsing(fn () => throw new RuntimeException('Writer must not be called'));
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['password' => '']]));
    expect(Hash::check('new-secret', $record->fresh()->password))->toBeTrue();
});

it('clears date range columns when the entire value is null', function () {
    $record = FormSaveRecord::create(['starts_at' => '2026-01-01', 'ends_at' => '2026-01-02']);
    $form = Form::make(FormSaveRecord::class)->urls(fn () => ['edit' => '/records/1']);
    $form->dateRange('period', 'Period', 'starts_at', 'ends_at');
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['period' => null]]));
    expect($record->fresh()->starts_at)->toBeNull()->and($record->fresh()->ends_at)->toBeNull();
});

it('uses the model transaction and leaves an outer rollback authoritative', function () {
    $record = brumeUser();
    $form = Form::make(User::class)->urls(fn () => ['edit' => '/users/1']);
    $form->text('name');
    $connection = $record->getConnection();
    expect($connection->getPdo()->inTransaction())->toBeTrue();
    $level = $connection->transactionLevel();
    $connection->beginTransaction();
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['name' => 'Changed']]));
    expect($connection->transactionLevel())->toBe($level + 1)->and($connection->getPdo()->inTransaction())->toBeTrue()->and($record->fresh()->name)->toBe('Changed');
    $connection->rollBack();
    expect($record->fresh()->name)->toBe('管理員')->and($connection->getPdo()->inTransaction())->toBeTrue();
});

it('rolls back database side effects made by a cancelling saving hook', function () {
    $record = brumeUser();
    $form = Form::make(User::class)->urls(fn () => ['edit' => '/users/1']);
    $form->saving(function ($r) {
        $r->name = 'Hook write';
        $r->save();

        return Feedback::toast('warning', 'Cancelled');
    });
    $result = (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => (object) []]));
    expect($result['cancelled'])->toBeTrue()->and($record->fresh()->name)->toBe('管理員');
});

it('archives before custom writers and persistence with an unsaved create record', function () {
    $form = Form::make(FormSaveRecord::class)->urls(fn ($r) => ['edit' => '/records/'.$r->getKey()]);
    $form->file('file')->saveUsing(function ($r, $value) {
        expect($value)->toBe('final/file.pdf')->and($r->exists)->toBeFalse();
        $r->file = $value;
    });
    app()->instance(UploadArchiver::class, new class extends UploadArchiver
    {
        public function archive(Form $form, Model $record, array &$values): void
        {
            expect($record->exists)->toBeFalse()->and($record->getConnection()->transactionLevel())->toBeGreaterThan(0);
            $values['file'] = 'final/file.pdf';
        }
    });
    $response = (new FormSaver)->save($form, null, Request::create('/', 'POST', ['values' => ['file' => ['token' => str_repeat('a', 40)]]]));
    expect(FormSaveRecord::findOrFail($response['id'])->file)->toBe('final/file.pdf');
});

it('does not report success when a model event refuses persistence', function () {
    $form = Form::make(FormSaveRecord::class)->urls(fn ($r) => ['edit' => '/records/'.$r->getKey()]);
    FormSaveRecord::saving(fn () => false);
    try {
        expect(fn () => (new FormSaver)->save($form, null, Request::create('/', 'POST', ['values' => (object) []])))->toThrow(LogicException::class);
        expect(FormSaveRecord::count())->toBe(0);
    } finally {
        FormSaveRecord::flushEventListeners();
    }
});

it('retains null-context create editability and ignores locked edit and disabled inputs', function () {
    $form = Form::make(FormSaveRecord::class)->urls(fn ($r) => ['edit' => '/records/'.$r->getKey()]);
    $form->text('amount')->readOnly(fn ($record) => $record !== null);
    $form->text('file')->disabled();
    $response = (new FormSaver)->save($form, null, Request::create('/', 'POST', ['values' => ['amount' => 'accepted create', 'file' => 'forged create']]));
    $record = FormSaveRecord::findOrFail($response['id']);
    expect($record->amount)->toBe('accepted create')->and($record->file)->toBeNull();
    $record->file = 'original';
    $record->save();
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['amount' => 'forged edit', 'file' => 'forged edit']]));
    expect($record->fresh()->amount)->toBe('accepted create')->and($record->fresh()->file)->toBe('original');
});

class RangeRoundTripResource extends Brume\Resource\Resource
{
    protected string $model = FormSaveRecord::class;

    public static string $rangeType = 'dateRange';

    protected function grid(Grid $grid): void
    {
        $grid->column('id');
    }

    protected function form(Form $form): void
    {
        $form->{self::$rangeType}('period', 'Period', 'starts_at', 'ends_at');
    }
}

it('replaces submitted range bounds through HTTP while retaining an omitted whole field', function ($type, $clear) {
    $this->actingAs(brumeUser(), 'brume');
    RangeRoundTripResource::$rangeType = $type;
    Route::middleware('web')->group(fn () => Route::brumeResource('range-records', RangeRoundTripResource::class));
    Route::getRoutes()->refreshNameLookups();
    $start = $type === 'dateRange' ? '2026-01-01' : '2026-01-01T10:00';
    $end = $type === 'dateRange' ? '2026-01-02' : '2026-01-02T11:00';
    $record = FormSaveRecord::create(['starts_at' => str_replace('T', ' ', $start), 'ends_at' => str_replace('T', ' ', $end)]);
    $bounds = array_diff_key(['start' => $start, 'end' => $end], array_flip($clear));
    $this->putJson('/range-records/'.$record->id, ['values' => ['period' => (object) $bounds]])->assertOk();
    $expected = ['start' => $bounds['start'] ?? null, 'end' => $bounds['end'] ?? null];
    $this->get('/range-records/'.$record->id.'/edit', ['X-Brume-Form' => '1'])->assertOk()->assertJsonPath('page.values.period', $expected);
    $this->putJson('/range-records/'.$record->id, ['values' => (object) []])->assertOk();
    $this->get('/range-records/'.$record->id.'/edit', ['X-Brume-Form' => '1'])->assertOk()->assertJsonPath('page.values.period', $expected);
})->with(['dateRange', 'datetimeRange'])->with([[['start']], [['end']], [['start', 'end']]]);

it('passes reference saving transformations through archive and field writers after validation', function () {
    $record = FormSaveRecord::create();
    $form = Form::make(FormSaveRecord::class)->urls(fn () => ['edit' => '/records/1']);
    $form->text('amount')->required();
    $form->file('file');
    $events = [];
    $form->submitted(function ($values) use (&$events) {
        $events[] = 'submitted';
    });
    $form->saving(function ($record, &$values) use (&$events) {
        $events[] = 'saving';
        $values['amount'] = 'normalized';
        $values['file'] = ['token' => str_repeat('b', 40)];
    });
    app()->instance(UploadArchiver::class, new class extends UploadArchiver
    {
        public function archive(Form $form, Model $record, array &$values): void
        {
            expect($values['amount'])->toBe('normalized')->and($values['file']['token'])->toBe(str_repeat('b', 40));
            $values['file'] = 'final/transformed.pdf';
        }
    });

    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['amount' => 'original']]));
    expect($record->fresh()->amount)->toBe('normalized')->and($record->fresh()->file)->toBe('final/transformed.pdf')->and($events)->toBe(['submitted', 'saving']);
    $events = [];
    expect(fn () => (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => (object) []])))->toThrow(ValidationException::class);
    expect($events)->toBe(['submitted']);
});
