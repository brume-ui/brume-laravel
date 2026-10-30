<?php

use Brume\Form\Form;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Models\User;
use Brume\Resource\Resource;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

class FormProtocolResource extends Resource
{
    protected string $model = User::class;

    protected string $name = '使用者';

    protected function grid(Grid $grid): void
    {
        $grid->column('name');
    }

    protected function form(Form $form): void
    {
        $form->text('username')->required();
        $form->text('name')->required();
        $form->image('avatar');
        $form->saving(function ($record) {
            if (! $record->exists) {
                $record->password = 'test-password';
            }
        });
        $form->viewUrl(fn ($record) => '/users/'.$record->id);
    }
}

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
    Storage::fake('local');
    Route::prefix('admin')->middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeResource('protocol-users', FormProtocolResource::class));
    Route::getRoutes()->refreshNameLookups();
});

it('validates resource create edit submission validation and uploads against the public protocol', function () {
    $create = $this->get('/admin/protocol-users/create', ['X-Brume-Form' => '1'])->assertOk();
    expect(JsonSchema::errors($create->json(), 'PageEnvelope'))->toBe([]);
    $saved = $this->postJson('/admin/protocol-users', ['values' => ['username' => 'protocol', 'name' => 'Protocol']])->assertOk();
    expect(JsonSchema::errors($saved->json(), 'FormSubmitResponse'))->toBe([])->and($saved->json('urls.view'))->toBe('/users/'.$saved->json('id'));
    $edit = $this->get($saved->json('urls.edit'), ['X-Brume-Form' => '1'])->assertOk();
    expect(JsonSchema::errors($edit->json(), 'PageEnvelope'))->toBe([])->and($edit->json('page.formId'))->toBe($create->json('page.formId'));
    $updated = $this->putJson('/admin/protocol-users/'.$saved->json('id'), ['values' => ['username' => 'protocol', 'name' => 'Updated']])->assertOk();
    expect(JsonSchema::errors($updated->json(), 'FormSubmitResponse'))->toBe([]);
    $invalid = $this->putJson('/admin/protocol-users/'.$saved->json('id'), ['values' => []])->assertUnprocessable();
    expect(JsonSchema::errors($invalid->json(), 'ErrorResponse'))->toBe([])->and($invalid->json('errors'))->toHaveKeys(['name', 'username']);
    foreach ([$create->json('page.uploadUrl'), $edit->json('page.uploadUrl')] as $url) {
        $upload = $this->post($url, ['field' => 'avatar', 'file' => UploadedFile::fake()->image('avatar.png')], ['Accept' => 'application/json'])->assertOk();
        expect(JsonSchema::errors($upload->json(), 'UploadTempResponse'))->toBe([]);
    }
});
