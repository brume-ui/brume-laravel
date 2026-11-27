<?php

use Brume\BrumeManager;
use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Form\FormValidator;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;

class SugarPost extends Model
{
    protected $table = 'sugar_posts';

    protected $guarded = [];

    public $timestamps = false;
}

function sugarForm(?SugarPost $record = null): Form
{
    return Form::make(SugarPost::class)->forRecord($record)->submitTo('put', '/sugar')->urls(fn ($r) => ['edit' => '/sugar/'.$r->id]);
}

function sugarNodes(Form $form): array
{
    return $form->toSchema(Request::create('/'))['form']['nodes'];
}

function sugarSave(Form $form, ?Model $record, array $values): array
{
    return (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => $values]));
}

function sugarErrors(Form $form, array $values): array
{
    try {
        FormValidator::validate($form, $values);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

// ---- idField --------------------------------------------------------------------------------

it('leaves idField out of the schema on create and shows it read-only on edit', function () {
    $form = sugarForm();
    $form->idField();
    $form->text('title');
    expect(array_column(array_column(sugarNodes($form), 'field'), 'name'))->toBe(['title']);

    $record = SugarPost::create(['title' => 'Hello']);
    $edit = sugarForm($record);
    $edit->idField();
    $edit->text('title');
    $nodes = sugarNodes($edit);
    expect(array_column(array_column($nodes, 'field'), 'name'))->toBe(['id', 'title'])
        ->and($nodes[0]['field'])->toMatchArray(['type' => 'display', 'name' => 'id', 'label' => 'ID'])
        ->and($edit->toSchema(Request::create('/'))['values']['id'])->toBe($record->id);
    $schema = ['schemaVersion' => BrumeManager::SCHEMA_VERSION, 'page' => $edit->toSchema(Request::create('/'))];
    expect(JsonSchema::errors($schema, 'PageEnvelope'))->toBe([]);
});

it('takes a custom name and label and omits it inside sections too', function () {
    $form = sugarForm();
    $form->section('S', fn (Form $s) => $s->idField('id', '編號'));
    $form->text('title');
    $nodes = $form->toSchema(Request::create('/'))['form']['nodes'];
    expect($nodes[0]['children'])->toBe([]);
});

it('keeps Form::id() as the form identity next to idField()', function () {
    $form = sugarForm()->id('sugar-form');
    $form->idField();
    $form->text('title');
    expect($form->formId())->toBe('sugar-form')
        ->and($form->toSchema(Request::create('/'))['formId'])->toBe('sugar-form');
});

it('does not write the id display field', function () {
    $record = SugarPost::create(['title' => 'A']);
    $form = sugarForm($record);
    $form->idField();
    $form->text('title');
    sugarSave($form, $record, ['id' => 999, 'title' => 'B']);
    expect($record->fresh()->title)->toBe('B')->and($record->fresh()->id)->toBe($record->id);
});

// ---- month ----------------------------------------------------------------------------------

it('offers twelve translated months', function (string $locale, string $first, string $last) {
    App::setLocale($locale);
    $form = sugarForm();
    $form->month('birth_month');
    $field = sugarNodes($form)[0]['field'];
    expect($field['type'])->toBe('select')
        ->and(array_column($field['options'], 'value'))->toBe(array_map('strval', range(1, 12)))
        ->and($field['options'][0]['label'])->toBe($first)
        ->and($field['options'][11]['label'])->toBe($last);
})->with([['en', 'January', 'December'], ['zh_TW', '一月', '十二月'], ['zh_CN', '一月', '十二月']]);

it('stores the month as an integer and reads it back as its option', function () {
    $form = sugarForm();
    $form->month('birth_month');
    $result = sugarSave($form, null, ['birth_month' => '7']);
    $row = SugarPost::find($result['id']);
    expect($row->getRawOriginal('birth_month'))->toBe(7);
    $edit = sugarForm($row);
    $edit->month('birth_month');
    expect($edit->toSchema(Request::create('/'))['values']['birth_month'])->toBe('7');
    expect(sugarErrors($form, ['birth_month' => '13']))->toHaveKey('birth_month')
        ->and(sugarErrors($form, ['birth_month' => '0']))->toHaveKey('birth_month')
        ->and(sugarErrors($form, ['birth_month' => '07']))->toHaveKey('birth_month')
        ->and(sugarErrors($form, ['birth_month' => null]))->toBe([]);
    $blank = sugarSave($form, null, ['birth_month' => '']);
    expect(SugarPost::find($blank['id'])->birth_month)->toBeNull();
});

it('lets saveUsing() keep a padded month string', function () {
    $form = sugarForm();
    $form->month('birth_month')->saveUsing(fn ($record, $value) => $record->forceFill(['title' => str_pad((string) $value, 2, '0', STR_PAD_LEFT)]));
    $result = sugarSave($form, null, ['birth_month' => '3']);
    expect(SugarPost::find($result['id'])->title)->toBe('03');
});

it('round-trips a padded month string with the README recipe', function () {
    $declare = fn (Form $form) => $form->month('month_text')
        ->value(fn ($record) => $record->month_text === null ? null : (int) $record->month_text)
        ->saveUsing(fn ($record, $value) => $record->forceFill([
            'month_text' => $value === null || $value === '' ? null : str_pad((string) $value, 2, '0', STR_PAD_LEFT),
        ]));
    $form = sugarForm();
    $declare($form);

    $blank = sugarSave($form, null, ['month_text' => '']);
    expect(SugarPost::find($blank['id'])->month_text)->toBeNull();

    $row = SugarPost::find(sugarSave($form, null, ['month_text' => '3'])['id']);
    expect($row->getRawOriginal('month_text'))->toBe('03');

    $edit = sugarForm($row);
    $declare($edit);
    $values = $edit->toSchema(Request::create('/'))['values'];
    expect($values['month_text'])->toBe('3');

    $resave = sugarForm($row);
    $declare($resave);
    sugarSave($resave, $row, ['month_text' => $values['month_text']]);
    expect($row->fresh()->month_text)->toBe('03');
});

// ---- year -----------------------------------------------------------------------------------

it('emits year as a plain integer number between 1900 and 2100 with no grouping', function () {
    $form = sugarForm();
    $form->year('founded_year');
    $field = sugarNodes($form)[0]['field'];
    expect($field)->toMatchArray(['type' => 'number', 'min' => 1900, 'max' => 2100])
        ->and(array_keys($field))->not->toContain('grouping')
        ->and(array_column($field['rules'], 'rule'))->toBe(['integer', 'min', 'max']);
    expect(sugarErrors($form, ['founded_year' => 1899]))->toHaveKey('founded_year')
        ->and(sugarErrors($form, ['founded_year' => 2101]))->toHaveKey('founded_year')
        ->and(sugarErrors($form, ['founded_year' => 1900]))->toBe([])
        ->and(sugarErrors($form, ['founded_year' => 2100]))->toBe([])
        ->and(sugarErrors($form, ['founded_year' => 1999.5]))->toHaveKey('founded_year');
    $form->year('other')->min(1000)->max(3000);
    expect(sugarNodes($form)[1]['field'])->toMatchArray(['min' => 1000, 'max' => 3000]);
});

// ---- timezone -------------------------------------------------------------------------------

it('offers searchable time zone identifiers and checks them', function () {
    $form = sugarForm();
    $form->timezone('zone');
    $field = sugarNodes($form)[0]['field'];
    expect($field)->toMatchArray(['type' => 'select', 'searchable' => true])
        ->and(array_column($field['options'], 'value'))->toBe(DateTimeZone::listIdentifiers())
        ->and(array_column($field['rules'] ?? [], 'rule'))->toBe([]);
    expect(sugarErrors($form, ['zone' => 'Asia/Taipei']))->toBe([])
        ->and(sugarErrors($form, ['zone' => 'UTC']))->toBe([])
        ->and(sugarErrors($form, ['zone' => 'Mars/Olympus']))->toHaveKey('zone')
        ->and(sugarErrors($form, ['zone' => 'asia/taipei']))->toHaveKey('zone')
        // Legacy aliases are not in the option list, so they are refused too.
        ->and(sugarErrors($form, ['zone' => 'US/Eastern']))->toHaveKey('zone')
        ->and(sugarErrors($form, ['zone' => 'Asia/Calcutta']))->toHaveKey('zone');
    $result = sugarSave($form, null, ['zone' => 'Asia/Taipei']);
    expect(SugarPost::find($result['id'])->zone)->toBe('Asia/Taipei');
    $schema = ['schemaVersion' => BrumeManager::SCHEMA_VERSION, 'page' => $form->toSchema(Request::create('/'))];
    expect(JsonSchema::errors($schema, 'PageEnvelope'))->toBe([]);
});

it('keeps timezone and month allowed in flat extras', function () {
    $form = Form::make(SugarPost::class);
    $form->month('a');
    $form->year('b');
    $form->timezone('c');
    expect($form->plainFields())->toHaveCount(3);
});

it('refuses searchable() on anything but a single select', function () {
    $form = sugarForm();
    expect(fn () => $form->multipleSelect('x')->searchable())->toThrow(InvalidArgumentException::class);
});

it('has the new validation and form strings in every locale', function () {
    foreach (['en', 'zh_TW', 'zh_CN'] as $locale) {
        $form = (require __DIR__.'/../../../lang/'.$locale.'/form.php');
        expect($form)->toHaveKeys(['captcha', 'months'])->and($form['months'])->toHaveCount(12);
        $validation = require __DIR__.'/../../../lang/'.$locale.'/validation.php';
        expect($validation)->toHaveKey('captcha');
    }
    foreach (['zh_TW', 'zh_CN'] as $locale) {
        expect(require __DIR__.'/../../../lang/'.$locale.'/validation.php')->toHaveKey('timezone');
    }
});
