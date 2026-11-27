<?php

use Brume\BrumeManager;
use Brume\Form\Conditions;
use Brume\Form\Fields\Captcha;
use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Grid\Actions\Action;
use Brume\Grid\Grid;
use Brume\Options\OptionSource;
use Brume\Tests\Support\JsonSchema;
use Brume\Tests\Support\Models\CellItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CaptchaPost extends Model
{
    protected $table = 'sugar_posts';

    protected $guarded = [];

    public $timestamps = false;
}

afterEach(fn () => Captcha::$availability = null);

/** What mews/captcha leaves behind after it drew an image whose text is $text. */
function captchaIssue(string $text = 'abc12'): void
{
    $key = Crypt::encrypt(Hash::make($text));
    session()->put('captcha', ['sensitive' => false, 'key' => $key, 'encrypt' => true]);
    Cache::put('captcha_'.md5($key), $text, 60);
}

function captchaForm(?Model $record = null): Form
{
    $form = Form::make(CaptchaPost::class)->forRecord($record)->submitTo('put', '/c')->urls(fn ($r) => ['edit' => '/c/'.$r->id]);
    $form->text('title');
    $form->captcha();

    return $form;
}

function captchaSave(Form $form, ?Model $record, array $values): array
{
    return (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => $values]));
}

it('emits a required captcha with an image url and only the required client rule', function () {
    $form = captchaForm();
    $page = $form->toSchema(Request::create('/'));
    $field = $page['form']['nodes'][1]['field'];
    expect($field)->toMatchArray(['type' => 'captcha', 'name' => '__captcha__', 'label' => 'Captcha', 'required' => true, 'src' => '/admin/_brume/captcha'])
        ->and(array_column($field['rules'], 'rule'))->toBe(['required'])
        ->and($page['values'])->not->toHaveKey('__captcha__');
    expect(JsonSchema::errors(['schemaVersion' => BrumeManager::SCHEMA_VERSION, 'page' => $page], 'PageEnvelope'))->toBe([]);
    $named = Form::make(CaptchaPost::class)->submitTo('put', '/c');
    $named->captcha('Prove it')->config('flat');
    expect($named->toSchema(Request::create('/'))['form']['nodes'][0]['field'])->toMatchArray(['label' => 'Prove it', 'src' => '/admin/_brume/captcha/flat']);
});

it('saves with a correct answer and the answer never reaches saving or the model', function () {
    $seen = null;
    $form = captchaForm();
    $form->saving(function ($record, array &$values) use (&$seen) {
        $seen = $values;
    });
    captchaIssue('abc12');
    $result = captchaSave($form, null, ['title' => 'Hi', '__captcha__' => 'ABC12']);
    $row = CaptchaPost::find($result['id']);
    expect($row->title)->toBe('Hi')->and($seen)->toBe(['title' => 'Hi'])
        ->and(array_key_exists('__captcha__', $row->getAttributes()))->toBeFalse();
});

it('answers a wrong captcha with a 422 on __captcha__ only and saves nothing', function () {
    $form = captchaForm();
    captchaIssue('abc12');
    $fired = false;
    $form->saving(function () use (&$fired) {
        $fired = true;
    });
    try {
        captchaSave($form, null, ['title' => 'Nope', '__captcha__' => 'wrong']);
        $this->fail('expected a validation failure');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['__captcha__'])
            ->and($e->errors()['__captcha__'][0])->toBe('The Captcha is incorrect.');
    }
    expect(CaptchaPost::count())->toBe(0)->and($fired)->toBeFalse();
    foreach ([null, '', '   ', ['x']] as $blank) {
        try {
            captchaSave($form, null, ['title' => 'Nope', '__captcha__' => $blank]);
            $this->fail('expected a validation failure');
        } catch (ValidationException $e) {
            expect(array_keys($e->errors()))->toBe(['__captcha__']);
        }
    }
    expect(CaptchaPost::count())->toBe(0);
});

it('uses a captcha answer once', function () {
    $form = captchaForm();
    captchaIssue('abc12');
    captchaSave($form, null, ['title' => 'One', '__captcha__' => 'abc12']);
    expect(fn () => captchaSave($form, null, ['title' => 'Two', '__captcha__' => 'abc12']))->toThrow(ValidationException::class)
        ->and(CaptchaPost::count())->toBe(1);
});

it('also protects the edit form', function () {
    $record = CaptchaPost::create(['title' => 'Old']);
    $form = captchaForm($record);
    captchaIssue('zz999');
    expect(fn () => captchaSave($form, $record, ['title' => 'New', '__captcha__' => 'bad']))->toThrow(ValidationException::class);
    expect($record->fresh()->title)->toBe('Old');
    captchaIssue('zz999');
    captchaSave($form, $record, ['title' => 'New', '__captcha__' => 'zz999']);
    expect($record->fresh()->title)->toBe('New');
});

it('translates the captcha message', function () {
    app()->setLocale('zh_TW');
    $form = captchaForm();
    captchaIssue('abc12');
    try {
        captchaSave($form, null, ['title' => 'x', '__captcha__' => 'bad']);
    } catch (ValidationException $e) {
        expect($e->errors()['__captcha__'][0])->toBe('驗證碼 不正確。');
    }
});

it('throws a clear LogicException when mews/captcha is missing', function () {
    Captcha::$availability = fn () => false;
    $form = Form::make(CaptchaPost::class);
    expect(fn () => $form->captcha())->toThrow(LogicException::class, 'composer require mews/captcha');
});

it('refuses a captcha outside the form page', function () {
    // Upload extras and action forms and quick create share plainFields().
    $form = Form::make(CaptchaPost::class);
    $form->captcha();
    expect(fn () => $form->plainFields())->toThrow(InvalidArgumentException::class);
    expect(fn () => Action::make('a', 'A')->form(fn (Form $f) => $f->captcha()))->toThrow(InvalidArgumentException::class);
    expect(fn () => Grid::make(CellItem::class)->quickCreate(fn (Form $f) => $f->captcha())->quickForm())->toThrow(InvalidArgumentException::class);
    expect(fn () => Form::make(CaptchaPost::class)->repeater('rows', 'Rows', fn (Form $row) => $row->captcha()))->toThrow(InvalidArgumentException::class);
    expect(fn () => Form::make(CaptchaPost::class)->relationPicker('rel', 'Rel', OptionSource::make(CaptchaPost::class))->rowForm()->captcha())->toThrow(InvalidArgumentException::class);
    expect(fn () => Form::make(CaptchaPost::class)->embeds('meta', 'Meta', fn (Form $e) => $e->captcha()))->toThrow(InvalidArgumentException::class);
    $twice = Form::make(CaptchaPost::class);
    $twice->captcha();
    expect(fn () => $twice->captcha())->toThrow(InvalidArgumentException::class);
    expect(fn () => Form::make(CaptchaPost::class)->captcha()->showWhen(Conditions::eq(['scope' => 'root', 'path' => 'title'], 'a')))->toThrow(LogicException::class);
    expect(fn () => Form::make(CaptchaPost::class)->captcha()->required(false))->toThrow(LogicException::class);
});

it('allows a captcha inside a section', function () {
    $form = Form::make(CaptchaPost::class)->submitTo('put', '/c');
    $form->section('Check', fn (Form $s) => $s->captcha());
    expect($form->toSchema(Request::create('/'))['form']['nodes'][0]['children'][0]['field']['type'])->toBe('captcha');
});

it('serves the image under the admin prefix with the session and tolerates the refresh parameter', function () {
    $this->actingAs(brumeUser(), 'brume');
    $first = $this->get('/admin/_brume/captcha');
    $first->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect($first->headers->get('Cache-Control'))->toContain('no-store');
    $key = session('captcha.key');
    expect($key)->not->toBeNull();
    $this->get('/admin/_brume/captcha?r=3')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect(session('captcha.key'))->not->toBe($key);
    $this->get('/admin/_brume/captcha/flat?r=1')->assertOk();
    $this->get('/admin/_brume/captcha/nope')->assertNotFound();
    $this->get('/admin/_brume/captcha/disable')->assertNotFound();
});

it('keeps the image behind the admin login', function () {
    $this->get('/admin/_brume/captcha')->assertRedirect();
});
