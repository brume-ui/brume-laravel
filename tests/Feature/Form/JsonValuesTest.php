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

it('reads actual HTTP row objects in root conditions', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    jsonValueRoute(function (Form $form) {
        $form->repeater('tags', 'Rows', fn (Form $row) => $row->text('name'));
        $form->text('note')->required()->showWhen(Conditions::eq(Conditions::root('tags.0.name'), 'yes'));
    }, $record);
    $this->postJson('/json-value', ['values' => ['tags' => [['_brume' => ['key' => 'one'], 'name' => 'yes']]]])
        ->assertUnprocessable()->assertJsonValidationErrors('note');
});

it('rejects duplicate selections across actual HTTP sibling row objects', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    jsonValueRoute(function (Form $form) {
        $form->repeater('tags', 'Rows', fn (Form $row) => $row->tags('codes')->excludeSelectedIn([Conditions::root('tags.*.codes')]));
    }, $record);
    $this->postJson('/json-value', ['values' => ['tags' => [
        ['_brume' => ['key' => 'one'], 'codes' => ['x']], ['_brume' => ['key' => 'two'], 'codes' => ['x']],
    ]]])->assertUnprocessable()->assertJsonValidationErrors('tags.0.codes');
});

it('preserves authoritative inactive nested JSON containers while editing their parent', function ($restriction) {
    $inner = [['name' => 'untouched', 'unknown' => 'preserve', 'payload' => (object) []], ['_brume' => ['key' => 'stored'], 'name' => 'second', 'unknown' => ['nested' => (object) []]]];
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada', 'tags' => [['_brume' => ['key' => 'one'], 'name' => 'old', 'inner' => $inner]]]);
    jsonValueRoute(function (Form $form) use ($restriction) {
        $form->repeater('tags', 'Rows', function (Form $row) use ($restriction) {
            $row->text('name');
            $row->text('mode');
            $inner = $row->repeater('inner', 'Inner', fn (Form $child) => $child->text('name'));
            $restriction === 'readonly' ? $inner->readOnly() : $inner->enableWhen(Conditions::eq(Conditions::row('mode'), 'on'));
        });
    }, $record);
    $this->postJson('/json-value', ['values' => ['tags' => [['_brume' => ['key' => 'one'], 'name' => 'changed', 'mode' => 'off', 'inner' => [['_brume' => ['key' => 'forged'], 'name' => 'forged']]]]]])->assertOk();
    $stored = json_decode($record->fresh()->getRawOriginal('tags'));
    expect($stored[0]->name)->toBe('changed')->and(json_encode($stored[0]->inner))->toBe(json_encode($inner));
})->with(['readonly', 'inactive']);

it('keeps preserved semantic containers attached through hook reorder and strips editable row extras', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada', 'tags' => [
        ['_brume' => ['key' => 'a'], 'locked' => [['unknown' => 'A', 'payload' => (object) []]]],
        ['_brume' => ['key' => 'b'], 'locked' => [['unknown' => 'B', 'payload' => (object) []]]],
    ]]);
    jsonValueRoute(function (Form $form) {
        $form->repeater('tags', 'Rows', function (Form $row) {
            $row->repeater('locked', 'Locked', fn (Form $child) => $child->text('name'))->readOnly();
            $row->repeater('editable', 'Editable', fn (Form $child) => $child->text('name'));
        });
    }, $record, function ($model, &$values) {
        $values['tags'] = array_reverse($values['tags']);
    });
    $rows = array_map(fn ($key) => ['_brume' => ['key' => $key], 'forged' => 'drop', 'editable' => [['_brume' => ['key' => 'new-'.$key], 'name' => 'safe', 'forged' => 'drop']]], ['a', 'b']);
    $this->postJson('/json-value', ['values' => ['tags' => $rows]])->assertOk();
    $stored = json_decode($record->fresh()->getRawOriginal('tags'));
    expect($stored[0]->_brume->key)->toBe('b')->and($stored[0]->locked[0]->unknown)->toBe('B')
        ->and($stored[1]->locked[0]->unknown)->toBe('A')->and(json_encode($stored[0]->locked[0]->payload))->toBe('{}');
    foreach ($stored as $row) {
        expect(property_exists($row, 'forged'))->toBeFalse()->and(property_exists($row->editable[0], 'forged'))->toBeFalse();
    }
});

it('resolves authoritative readonly row controls before root conditions after HTTP reorder', function ($keys, $index, $restriction) {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada', 'tags' => [
        ['_brume' => ['key' => 'a'], 'name' => 'yes'], ['_brume' => ['key' => 'b'], 'name' => 'no'],
    ]]);
    jsonValueRoute(function (Form $form) use ($index, $restriction) {
        $form->repeater('tags', 'Rows', fn (Form $row) => $row->text('name')->{$restriction}());
        $form->text('note')->required()->showWhen(Conditions::eq(Conditions::root('tags.'.$index.'.name'), 'yes'));
    }, $record);
    $rows = array_map(fn ($key) => ['_brume' => ['key' => $key], 'name' => 'no'], $keys);
    $this->postJson('/json-value', ['values' => ['tags' => $rows]])->assertUnprocessable()->assertJsonValidationErrors('note');
})->with([
    'initial readonly order' => [['a', 'b'], 0, 'readOnly'],
    'moved readonly row' => [['b', 'a'], 1, 'readOnly'],
    'initial disabled order' => [['a', 'b'], 0, 'disabled'],
    'moved disabled row' => [['b', 'a'], 1, 'disabled'],
]);

it('canonicalizes root numeric paths with their actual money declarations', function ($amount) {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    jsonValueRoute(function (Form $form) {
        $form->repeater('tags', 'Rows', fn (Form $row) => $row->decimal('amount')->decimals(2));
        $form->text('note')->required()->showWhen(Conditions::eq(Conditions::root('tags.0.amount'), '500.00'));
    }, $record);
    $this->postJson('/json-value', ['values' => ['tags' => [['_brume' => ['key' => 'one'], 'amount' => $amount]]]])
        ->assertUnprocessable()->assertJsonValidationErrors('note');
})->with(['500', '500.0', '500.00']);

it('resolves legacy descendants inside authoritative readonly containers for evaluation only', function () {
    $original = [['_brume' => ['key' => 'outer'], 'inner' => [['name' => 'yes', 'unknown' => 'keep']]]];
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada', 'tags' => $original]);
    jsonValueRoute(function (Form $form) {
        $form->repeater('tags', 'Rows', fn (Form $row) => $row->repeater('inner', 'Inner', fn (Form $child) => $child->text('name')->readOnly())->readOnly());
        $form->text('note')->required()->showWhen(Conditions::eq(Conditions::root('tags.0.inner.0.name'), 'yes'));
    }, $record);
    $this->postJson('/json-value', ['values' => ['tags' => [['_brume' => ['key' => 'outer'], 'inner' => []]]]])->assertUnprocessable()->assertJsonValidationErrors('note');
    expect($record->fresh()->tags)->toBe($original);
});
