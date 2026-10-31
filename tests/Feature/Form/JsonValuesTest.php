<?php

use Brume\Form\Conditions;
use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Tests\Support\Models\CellItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

function jsonValueRoute(Closure $fields, ?CellItem $record = null, ?Closure $saving = null): void
{
    Route::middleware('web')->post('/json-value', function (Request $request) use ($fields, $record, $saving) {
        $form = Form::make(CellItem::class)->urls(fn () => ['edit' => '/json-value/1']);
        $form->text('customer')->default('Ada');
        $form->number('shop_id');
        $fields($form);
        if ($saving !== null) {
            $form->saving($saving);
        }

        return (new FormSaver)->save($form, $record, $request);
    });
}

it('distinguishes actual HTTP custom empty objects from empty arrays in conditions', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    jsonValueRoute(function (Form $form) {
        $form->component('tags', 'JSON', 'JSON');
        $form->text('note')->required()->showWhen(Conditions::empty(Conditions::root('tags')));
    }, $record);
    $this->postJson('/json-value', ['values' => ['tags' => (object) []]])->assertOk();
    expect($record->fresh()->getRawOriginal('tags'))->toBe('{}');
    $this->postJson('/json-value', ['values' => ['tags' => []]])->assertUnprocessable()->assertJsonValidationErrors('note');
});

it('preserves nested custom containers and middleware scalar changes before saving hooks', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    $seen = null;
    jsonValueRoute(fn (Form $form) => $form->component('tags', 'JSON', 'JSON')->childRules(['nested' => ['array']]), $record, function ($model, &$values) use (&$seen) {
        $seen = json_encode($values['tags']);
        $values['tags'] = [(object) []];
    });
    $value = (object) ['empty' => (object) [], 'list' => [], 'nested' => [(object) [], []], 'blank' => ' ', 'text' => ' Ada '];
    $this->postJson('/json-value', ['values' => ['tags' => $value]])->assertOk();
    expect($seen)->toBe('{"empty":{},"list":[],"nested":[{},[]],"blank":null,"text":"Ada"}')
        ->and($record->fresh()->getRawOriginal('tags'))->toBe('[{}]');
});

it('retains authoritative readonly custom object shape for conditions and snapshots', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada', 'tags' => (object) []]);
    jsonValueRoute(function (Form $form) {
        $form->component('tags', 'JSON', 'JSON')->readOnly();
        $form->text('note')->required()->showWhen(Conditions::empty(Conditions::root('tags')));
    }, $record);
    $this->postJson('/json-value', ['values' => ['tags' => []]])->assertOk();
    $form = Form::make(CellItem::class)->forRecord($record)->submitTo('post', '/json-value');
    $form->component('tags', 'JSON', 'JSON')->readOnly();
    expect(json_encode($form->toSchema(Request::create('/'))['values']['tags']))->toBe('{}');
});

it('rejects list shaped HTTP repeater rows without suppressing dependent validation', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    jsonValueRoute(function (Form $form) {
        $form->repeater('tags', 'Rows', fn (Form $row) => $row->text('name'));
        $form->text('note')->required()->showWhen(Conditions::empty(Conditions::root('tags')));
    }, $record);
    $this->postJson('/json-value', ['values' => ['tags' => [[]]]])->assertUnprocessable()->assertJsonValidationErrors(['tags', 'note']);
});

it('preserves nested repeater custom JSON on persistence reload and readonly edits', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    $fields = function (Form $form) {
        $form->repeater('tags', 'Rows', function (Form $row) {
            $row->component('payload', 'Payload', 'JSON');
            $row->repeater('inner', 'Inner', fn (Form $child) => $child->component('payload', 'Payload', 'JSON')->readOnly());
        });
    };
    jsonValueRoute($fields, $record);
    $value = [['_brume' => ['key' => 'row'], 'payload' => (object) ['empty' => (object) [], 'list' => []]]];
    $this->postJson('/json-value', ['values' => ['tags' => $value]])->assertOk();
    expect($record->fresh()->getRawOriginal('tags'))->toBe('[{"_brume":{"key":"row"},"payload":{"empty":{},"list":[]}}]');
    $record->update(['tags' => [['_brume' => ['key' => 'row'], 'payload' => (object) [], 'inner' => [['_brume' => ['key' => 'nested'], 'payload' => (object) []]]]]]);
    $value[0]['inner'] = [['_brume' => ['key' => 'nested'], 'payload' => []]];
    $this->postJson('/json-value', ['values' => ['tags' => $value]])->assertOk();
    $form = Form::make(CellItem::class)->forRecord($record->fresh())->submitTo('post', '/json-value');
    $fields($form);
    expect(json_encode($form->toSchema(Request::create('/'))['values']['tags'][0]['inner'][0]['payload']))->toBe('{}');
});

it('reads current JSON cast mutations without restoring stale persisted container shapes', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada', 'tags' => (object) []]);
    $field = Form::make(CellItem::class)->component('tags', 'JSON', 'JSON');
    $record->tags = [];
    expect(json_encode($field->valueFor($record)))->toBe('[]');
    $record->tags = (object) ['empty' => (object) []];
    expect(json_encode($field->valueFor($record)))->toBe('{"empty":{}}');
});

it('accepts object HTTP value envelopes and rejects list envelopes', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    jsonValueRoute(fn () => null, $record);
    $this->postJson('/json-value', ['values' => (object) []])->assertOk();
    $this->postJson('/json-value', ['values' => []])->assertUnprocessable()->assertJsonValidationErrors('values');
});
