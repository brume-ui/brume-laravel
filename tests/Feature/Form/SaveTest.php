<?php

use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Grid\Actions\Feedback;
use Brume\Models\User;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

it('saves only editable declarations and keeps blank passwords', function () {
    $record = brumeUser();
    $password = $record->password;
    $form = Form::make(User::class)->urls(fn () => ['edit' => '/users/1']);
    $form->urls(fn ($r) => ['edit' => '/users/'.$r->getKey()]);
    $form->text('name');
    $form->text('username')->readOnly();
    $form->text('locked')->disabled();
    $form->display('sold');
    $form->password('password');
    $response = (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['name' => 'New', 'username' => 'forged', 'locked' => 'bad', 'sold' => 3, 'is_admin' => true, 'password' => '']]));
    expect($record->fresh()->name)->toBe('New')->and($record->fresh()->username)->toBe('admin')->and($record->fresh()->password)->toBe($password)
        ->and(JsonSchema::errors($response, 'FormSubmitResponse'))->toBe([]);
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['password' => 'new-secret']]));
    expect(Hash::check('new-secret', $record->fresh()->password))->toBeTrue();
});

it('cancels before validation or inside the transaction', function ($hook) {
    $record = brumeUser();
    $form = Form::make(User::class)->urls(fn () => ['edit' => '/users/1']);
    $form->text('name')->required();
    $form->$hook(fn () => Feedback::toast('error', 'Stop')->redirect('/safe'));
    $response = (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['name' => 'Changed']]));
    expect($record->fresh()->name)->toBe('管理員')->and($response['cancelled'])->toBeTrue()->and($response)->not->toHaveKey('id')
        ->and(JsonSchema::errors($response, 'FormSubmitResponse'))->toBe([]);
})->with(['submitted', 'saving']);

it('rolls back writes when saved validation fails', function () {
    $record = brumeUser();
    $form = Form::make(User::class)->urls(fn () => ['edit' => '/users/1']);
    $form->text('name');
    $form->saved(fn () => throw ValidationException::withMessages(['total' => 'Invalid total']));
    try {
        (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['name' => 'Changed']]));
        test()->fail('Expected validation failure');
    } catch (ValidationException $e) {
        expect($e->status)->toBe(422)->and($e->errors())->toHaveKey('total');
    }
    expect($record->fresh()->name)->toBe('管理員');
});

it('orders lifecycle hooks and merges saved feedback and safe urls', function () {
    $calls = [];
    $form = Form::make(User::class)->submitTo('post', '/users')->name('User');
    $form->creating(function ($f) use (&$calls) {
        $calls[] = 'creating';
    });
    $form->text('username');
    $form->password('password');
    $form->text('name');
    foreach (['submitted', 'saving', 'saved'] as $hook) {
        $form->$hook(function () use (&$calls, $hook) {
            $calls[] = $hook;

            return $hook === 'saved' ? Feedback::make()->redirect('/done') : null;
        });
    }
    $form->urls(fn ($r) => ['list' => '/users', 'edit' => '/users/'.$r->getKey(), 'create' => '/users/create']);
    $form->after(['list', 'view'], 'list');
    expect($form->toSchema(Request::create('/'))['after']['options'])->toBe(['list']);
    $response = (new FormSaver)->save($form, null, Request::create('/', 'POST', ['values' => ['username' => 'new', 'password' => 'secret', 'name' => 'New']]));
    expect($calls)->toBe(['creating', 'submitted', 'saving', 'saved'])->and($response['feedback']['redirect'])->toBe('/done')
        ->and($response['feedback'])->toHaveKey('toast')->and(JsonSchema::errors($response, 'FormSubmitResponse'))->toBe([]);
});
