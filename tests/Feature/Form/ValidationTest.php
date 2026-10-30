<?php

use Brume\Form\ClientRules;
use Brume\Form\Form;
use Brume\Form\FormValidator;
use Brume\Models\User;
use Illuminate\Validation\ValidationException;

it('validates editable declarations only and preserves nested error paths', function () {
    $form = Form::make(User::class);
    $form->text('locked')->required()->disabled();
    $form->text('readonly')->required()->readOnly(fn ($record) => $record?->exists === true);
    $form->display('display')->required();
    $form->hidden('hidden')->required();
    $form->embeds('meta', 'Meta', fn ($f) => $f->text('color', 'Color')->required());
    $record = new User;
    $record->exists = true;
    expect(FormValidator::validate($form, ['locked' => 'forged', 'readonly' => 'forged', 'hidden' => 'yes', 'meta' => ['color' => 'red', 'other' => 'bad'], 'other' => 'bad'], $record))->toBe(['hidden' => 'yes', 'meta' => ['color' => 'red']]);
    try {
        FormValidator::validate($form, ['hidden' => 'yes'], $record);
        test()->fail('Expected validation failure');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['meta.color']);
    }
});

it('matches client and server messages', function ($locale, $method, $configure, $value, $rule) {
    app()->setLocale($locale);
    $form = Form::make(User::class);
    $field = $form->$method('value', '欄位');
    $configure($field);
    try {
        FormValidator::validate($form, ['value' => $value], null);
        test()->fail('Expected validation failure');
    } catch (ValidationException $e) {
        $client = collect(ClientRules::for($field, 'value', null))->firstWhere('rule', $rule);
        expect($client['message'])->toBe($e->errors()['value'][0]);
    }
})->with(['en', 'zh_TW'])->with([
    ['text', fn ($f) => $f->required(), '', 'required'],
    ['text', fn ($f) => $f->minLength(3), 'a', 'minLength'],
    ['text', fn ($f) => $f->rules('max:2'), 'abc', 'maxLength'],
    ['email', fn ($f) => $f, 'bad', 'email'],
    ['url', fn ($f) => $f, 'javascript:alert(1)', 'url'],
    ['number', fn ($f) => $f, 'abc', 'integer'],
    ['number', fn ($f) => $f->min(3), 2, 'min'],
    ['number', fn ($f) => $f->max(3), 4, 'max'],
    ['text', fn ($f) => $f->rules('numeric'), 'abc', 'numeric'],
    ['mobile', fn ($f) => $f, '912345678', 'pattern'],
    ['multipleFile', fn ($f) => $f->max(1), [['token' => 'a'], ['token' => 'b']], 'maxFiles'],
    ['text', fn ($f) => $f->required()->messages(['required' => '請填名稱']), '', 'required'],
]);

it('accepts the mobile pattern and preserves its public source', function () {
    $form = Form::make(User::class);
    $field = $form->mobile('phone');
    expect(FormValidator::validate($form, ['phone' => '0912345678']))->toBe(['phone' => '0912345678'])
        ->and(ClientRules::for($field, 'phone', null)[0]['pattern'])->toBe('^09\\d{8}$');
});

it('validates nested ranges and strict choice shapes', function ($method, $value, $path) {
    $form = Form::make(User::class);
    if ($method === 'dateRange') {
        $form->dateRange('value', 'Period', 'start', 'end');
    } else {
        $form->$method('value')->options(['a' => 'A', '1' => 'One']);
    }
    try {
        FormValidator::validate($form, ['value' => $value]);
        test()->fail('Expected validation failure');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($path);
    }
})->with([
    ['select', 'b', 'value'], ['select', 1, 'value'], ['multipleSelect', ['a', 'b'], 'value.1'],
    ['multipleSelect', 'a', 'value'], ['checkbox', [1], 'value.0'],
    ['dateRange', ['start' => '2026-01-02', 'end' => '2026-01-01'], 'value.end'],
    ['dateRange', ['start' => '2026-02-31'], 'value.start'],
    ['dateRange', 'wrong', 'value'],
]);

it('ignores the scoped editing record in unique validation', function () {
    $record = brumeUser(['username' => 'alice']);
    $other = brumeUser(['username' => 'bob']);
    $form = Form::make(User::query()->whereKey($record->getKey()));
    $field = $form->text('username')->unique();
    expect($field->toSchema())->toHaveKey('type');
    expect(FormValidator::validate($form, ['username' => 'alice'], $record))->toBe(['username' => 'alice']);
    expect(fn () => FormValidator::validate($form, ['username' => 'bob'], $record))->toThrow(ValidationException::class);
});

it('uses application overrides before package fallback at exact nested paths', function () {
    app()->setLocale('zh_TW');
    $form = Form::make(User::class);
    $form->embeds('meta', 'Meta', fn ($f) => $f->text('color', '顏色')->required());
    $field = $form->fields()['meta.color'];
    expect(ClientRules::for($field, 'meta.color', null)[0]['message'])->toBe('顏色 為必填。');
    app('translator')->addLines(['validation.required' => ':attribute APP'], 'zh_TW');
    expect(ClientRules::for($field, 'meta.color', null)[0]['message'])->toBe('顏色 APP');
    app('translator')->addLines(['validation.custom.meta.color.required' => ':attribute CUSTOM'], 'zh_TW');
    expect(ClientRules::for($field, 'meta.color', null)[0]['message'])->toBe('顏色 CUSTOM');
    try {
        FormValidator::validate($form, []);
    } catch (ValidationException $e) {
        expect($e->errors()['meta.color'][0])->toBe('顏色 CUSTOM');
    }
});

it('formats each rule independently when required is also declared', function () {
    $form = Form::make(User::class);
    $field = $form->text('name')->required()->maxLength(2);
    $rules = ClientRules::for($field, 'name', null);
    try {
        FormValidator::validate($form, ['name' => 'abc']);
    } catch (ValidationException $e) {
        expect($rules[1]['message'])->toBe($e->errors()['name'][0]);
    }
});

it('keeps Unicode lengths, wire dates and decimal strings intact', function () {
    $form = Form::make(User::class);
    $form->text('name')->maxLength(2);
    $form->datetime('at');
    $form->decimal('amount')->decimals(2)->min(0);
    $form->tags('tags')->max(3)->maxLength(2);
    $values = ['name' => '😀字', 'at' => '2026-10-31T12:30', 'amount' => '999999999999999.10', 'tags' => ['😀字', 'ab', 'xy']];
    expect(FormValidator::validate($form, $values))->toBe($values);
});

it('resolves rule closures with the editing record and custom pattern messages', function () {
    $record = new User(['name' => 'Alice']);
    $form = Form::make(User::class);
    $field = $form->text('code')->rules(fn ($r) => $r?->name === 'Alice' ? ['required'] : [])->pattern('^a~b$', 'Invalid code');
    expect(FormValidator::validate($form, ['code' => 'a~b'], $record))->toBe(['code' => 'a~b']);
    expect(fn () => FormValidator::validate($form, [], $record))->toThrow(ValidationException::class);
    expect(collect(ClientRules::for($field, 'code', $record))->firstWhere('rule', 'pattern')['message'])->toBe('Invalid code');
    try {
        FormValidator::validate($form, ['code' => 'wrong'], $record);
    } catch (ValidationException $e) {
        expect($e->errors()['code'][0])->toBe('Invalid code');
    }
});

it('rejects undeclared blank choice items through the direct validator contract', function ($method, $blank) {
    $form = Form::make(User::class);
    $form->$method('choices', 'Choices')->options(['a' => 'A']);
    try {
        FormValidator::validate($form, ['choices' => [$blank]]);
        test()->fail('Expected invalid choice');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('choices.0');
    }
})->with(['multipleSelect', 'checkbox'])->with(['', '   ']);

it('preserves optional empty lists and explicitly declared blank choice values', function () {
    $form = Form::make(User::class);
    $form->multipleSelect('choices')->options(['' => 'Empty', '   ' => 'Spaces', 'a' => 'A']);
    expect(FormValidator::validate($form, ['choices' => []]))->toBe(['choices' => []])
        ->and(FormValidator::validate($form, ['choices' => ['', '   ', 'a']]))->toBe(['choices' => ['', '   ', 'a']]);
});

it('prioritizes field messages on range bounds and wildcard children over host custom lines', function ($choice) {
    app()->setLocale('en');
    app('translator')->addLines([
        'validation.custom.period.end.after_or_equal' => 'HOST RANGE',
        'validation.custom.choices.*.in' => 'HOST CHOICE',
    ], 'en');
    $form = Form::make(User::class);
    $form->dateRange('period', 'Period label', 'start', 'end')->messages(['after_or_equal' => ':attribute field order']);
    $form->multipleSelect('choices', 'Choice label')->options(['a' => 'A'])->messages(['in' => ':attribute field choice']);
    try {
        FormValidator::validate($form, ['period' => ['start' => '2026-02-02', 'end' => '2026-02-01'], 'choices' => [$choice]]);
        test()->fail('Expected validation failure');
    } catch (ValidationException $e) {
        expect($e->errors()['period.end'][0])->toBe('Period label field order')
            ->and($e->errors()['choices.0'][0])->toBe('Choice label field choice');
    }
})->with(['bad', '', '   ']);
