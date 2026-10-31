<?php

use Brume\Form\Form;
use Brume\Models\User;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

it('outputs nested values, date ranges and password without exposing hidden attributes', function () {
    $record = new User;
    $record->mergeCasts(['meta' => 'array']);
    $record->forceFill(['id' => 7, 'name' => 'Alice', 'password' => 'secret', 'meta' => ['color' => 'red'], 'starts_at' => '2026-11-01', 'ends_at' => '2026-11-11']);
    $record->mergeCasts(['meta' => 'array']);
    $form = Form::make(User::class)->name('User')->forRecord($record)->submitTo('put', '/users/7');
    $form->text('name');
    $form->text('password');
    $form->password('new_password');
    $form->embeds('meta', 'Meta', fn ($f) => $f->text('color'));
    $form->dateRange('period', 'Period', 'starts_at', 'ends_at');
    $page = $form->toSchema(Request::create('/'));
    expect(json_decode(json_encode($page['values']), true))->toBe(['name' => 'Alice', 'password' => null, 'new_password' => '', 'meta' => ['color' => 'red'], 'period' => ['start' => '2026-11-01', 'end' => '2026-11-11']]);
});

it('serializes every field variant exactly', function (string $method, array $extra) {
    $form = Form::make(User::class);
    $field = in_array($method, ['dateRange', 'datetimeRange']) ? $form->$method('value', 'Value', 'starts_at', 'ends_at') : $form->$method('value', 'Value');
    $default = match ($method) {
        'password' => '', 'dateRange', 'datetimeRange' => ['start' => null, 'end' => null],
        'multipleSelect', 'checkbox', 'tags', 'multipleImage', 'multipleFile' => [],
        'switch' => false, default => null,
    };
    expect(Arr::except($field->toSchema(null), ['rules']))->toBe(($default !== null ? ['defaultValue' => $default] : []) + ['type' => match ($method) {
        'image', 'multipleImage', 'file', 'multipleFile' => 'upload', 'switch' => 'switch', default => $method
    }, 'name' => 'value', 'label' => 'Value'] + $extra);
})->with([
    ['text', []], ['textarea', []], ['email', []], ['url', []], ['mobile', []], ['password', []], ['hidden', []], ['display', []], ['number', []], ['decimal', ['decimals' => 2]], ['currency', ['decimals' => 0, 'symbol' => 'NT$']], ['date', []], ['datetime', []], ['dateRange', []], ['datetimeRange', []], ['select', ['options' => []]], ['multipleSelect', ['options' => []]], ['radio', ['options' => []]], ['checkbox', ['options' => []]], ['switch', []], ['tags', []],
    ['image', ['kind' => 'image', 'multiple' => false, 'accept' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], 'maxSize' => 10485760]],
    ['multipleImage', ['kind' => 'image', 'multiple' => true, 'accept' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], 'maxSize' => 10485760]],
    ['file', ['kind' => 'file', 'multiple' => false, 'accept' => [], 'maxSize' => 10485760]],
    ['multipleFile', ['kind' => 'file', 'multiple' => true, 'accept' => [], 'maxSize' => 10485760]],
]);

it('resolves defaults, explicit hidden reads and readonly callbacks', function () {
    $form = Form::make(User::class)->submitTo('post', '/users');
    $form->text('name')->default('New')->required()->readOnly(fn ($r) => $r?->name === 'Locked');
    $form->text('secret')->attribute('password')->value(fn ($r) => 'explicit');
    $form->password('password')->default('never');
    expect($form->toSchema(Request::create('/'))['values'])->toBe(['name' => 'New', 'secret' => null, 'password' => '']);
    $record = new User(['name' => 'Locked']);
    $form->forRecord($record);
    $schema = $form->toSchema(Request::create('/'));
    expect($schema['values']['secret'])->toBe('explicit')->and($schema['form']['nodes'][0]['field']['readOnly'])->toBeTrue()
        ->and($schema['form']['nodes'][2]['field']['help'])->toBe(__('brume::form.password_keep'));
});

it('calculates presentation and width at the documented boundaries', function () {
    $f = Form::make(User::class)->submitTo('post', '/');
    foreach (range(1, 3) as $n) {
        $f->text('field'.$n);
    }
    expect($f->toSchema(Request::create('/')))->toMatchArray(['presentation' => 'modal', 'size' => 'sm']);
    foreach (range(4, 16) as $n) {
        $f->text('field'.$n);
    }
    expect($f->toSchema(Request::create('/')))->toMatchArray(['presentation' => 'modal', 'size' => 'lg']);
    $f->text('seventeen');
    expect($f->toSchema(Request::create('/'))['presentation'])->toBe('page');
    $g = Form::make(User::class)->submitTo('post', '/');
    foreach (range(1, 3) as $n) {
        $g->section('Section', fn ($s) => $s->text('s'.$n));
    }
    expect($g->toSchema(Request::create('/'))['presentation'])->toBe('page');
    $long = Form::make(User::class)->submitTo('post', '/');
    $long->textarea('body');
    expect($long->toSchema(Request::create('/'))['size'])->toBe('md');
});

it('rejects duplicate and unsafe paths', function (string $path) {
    $f = Form::make(User::class);
    $f->text('meta.color');
    if ($path === 'meta.color') {
        $f->embeds('meta', 'Meta', fn ($e) => $e->text('color'));
    } else {
        $f->text($path);
    }
})->with(['meta.color', '__proto__.x', 'constructor.x', 'a.prototype.x', '', 'a..b'])->throws(InvalidArgumentException::class);

it('rejects rows wider than twelve in local', function () {
    app()->detectEnvironment(fn () => 'local');
    $f = Form::make(User::class);
    $f->submitTo('post', '/');
    $f->row(function ($r) {
        $r->text('a')->width(7);
        $r->text('b')->width(6);
    });
    $f->toSchema(Request::create('/'));
})->throws(InvalidArgumentException::class);

it('formats dates and fixed decimals without floating point loss', function () {
    $record = new User;
    $record->forceFill(['day' => '2026-11-01 12:30:59', 'moment' => '2026-11-01 12:30:59', 'amount' => '9007199254740993.1', 'quantity' => '12', 'enabled' => 1]);
    $f = Form::make(User::class)->forRecord($record)->submitTo('put', '/');
    $f->date('day');
    $f->datetime('moment');
    $f->decimal('amount');
    $f->number('quantity');
    $f->switch('enabled');
    expect($f->toSchema(Request::create('/'))['values'])->toBe(['day' => '2026-11-01', 'moment' => '2026-11-01T12:30', 'amount' => '9007199254740993.10', 'quantity' => 12, 'enabled' => true]);
});

it('serializes layout trees with relative field names and complete registered paths', function () {
    $form = Form::make(User::class)->submitTo('post', '/')->tabs();
    $form->section('Main', function ($f) {
        $f->row(function ($r) {
            $r->text('a')->width(4);
            $r->text('b')->width(8);
        });
        $f->columns(function ($c) {
            $c->column(5, fn ($f) => $f->block('Card', fn ($b) => $b->text('c')));
            $c->column(7, fn ($f) => $f->divider('Break'));
        });
        $f->embeds('meta', 'Meta', fn ($e) => $e->embeds('design', 'Design', fn ($d) => $d->text('color')->default('blue')));
    }, 'Description');
    $schema = $form->toSchema(Request::create('/'));
    expect($schema['form'])->toBe(['layout' => 'stacked', 'nodes' => [
        ['type' => 'section', 'key' => 'section-1', 'title' => 'Main', 'description' => 'Description', 'children' => [
            ['type' => 'row', 'children' => [
                ['type' => 'field', 'field' => ['type' => 'text', 'name' => 'a', 'label' => 'A', 'width' => 4]],
                ['type' => 'field', 'field' => ['type' => 'text', 'name' => 'b', 'label' => 'B', 'width' => 8]],
            ]],
            ['type' => 'columns', 'columns' => [
                ['width' => 5, 'children' => [['type' => 'block', 'title' => 'Card', 'children' => [['type' => 'field', 'field' => ['type' => 'text', 'name' => 'c', 'label' => 'C']]]]]],
                ['width' => 7, 'children' => [['type' => 'divider', 'label' => 'Break']]],
            ]],
            ['type' => 'embeds', 'name' => 'meta', 'label' => 'Meta', 'children' => [
                ['type' => 'embeds', 'name' => 'design', 'label' => 'Design', 'children' => [['type' => 'field', 'field' => ['defaultValue' => 'blue', 'type' => 'text', 'name' => 'color', 'label' => 'Color']]]],
            ]],
        ]],
    ], 'sections' => 'tabs']);
    expect(array_keys($form->fields()))->toBe(['a', 'b', 'c', 'meta.design.color'])
        ->and($schema['values']['meta']->design->color)->toBe('blue');
});

it('preserves empty embedded objects and their long-field sizing', function () {
    $form = Form::make(User::class)->submitTo('post', '/');
    $form->embeds('meta', 'Meta', fn ($e) => null);
    $page = $form->toSchema(Request::create('/'));
    expect(json_encode($page['values']))->toBe('{"meta":{}}')->and($page['size'])->toBe('md');
});

it('keeps hidden values null for typed fields and nested roots', function () {
    $record = new User;
    $record->mergeCasts(['meta' => 'array', 'choices' => 'array']);
    $record->forceFill(['meta' => ['color' => 'secret'], 'enabled' => true, 'choices' => ['private']]);
    $record->setHidden(['meta', 'enabled', 'choices']);
    $form = Form::make(User::class)->forRecord($record)->submitTo('put', '/');
    $form->switch('enabled');
    $form->multipleSelect('choices');
    $form->embeds('meta', 'Meta', fn ($e) => $e->text('color'));
    expect(json_decode(json_encode($form->toSchema(Request::create('/'))['values']), true))->toBe(['enabled' => null, 'choices' => null, 'meta' => ['color' => null]]);
});

it('normalizes select values, range defaults and decimal rounding', function () {
    $f = Form::make(User::class)->submitTo('post', '/');
    $f->select('select')->default(12)->options([12 => ['Twelve', 2]]);
    $f->radio('radio')->options([1 => 'One'])->default(1);
    $f->multipleSelect('multi')->default([1, 2]);
    $f->checkbox('check')->default([3]);
    $f->tags('tags')->options(['a' => 'A'])->max(3)->maxLength(10);
    $f->currency('money')->decimals(2)->default('-999.995');
    $f->datetimeRange('period', 'Period', 'starts_at', 'ends_at')->default(['start' => '2026-11-01 12:30:59']);
    $p = $f->toSchema(Request::create('/'));
    expect($p['values'])->toBe(['select' => '12', 'radio' => '1', 'multi' => ['1', '2'], 'check' => ['3'], 'tags' => [], 'money' => '-1000.00', 'period' => ['start' => '2026-11-01T12:30', 'end' => null]])
        ->and($p['form']['nodes'][0]['field']['options'])->toBe([['value' => '12', 'label' => 'Twelve', 'depth' => 2]]);
});

it('maps archived uploads through storage and exposes only declared extras', function () {
    Storage::fake('public');
    $r = new User;
    $r->mergeCasts(['photos' => 'array']);
    $r->forceFill(['photos' => [['path' => 'photos/one.jpg', 'url' => 'javascript:bad', 'extra' => ['caption' => 'One', 'secret' => 'private']]], 'avatar' => 'photos/two.jpg']);
    $f = Form::make(User::class)->forRecord($r)->submitTo('put', '/');
    $f->id('photos');
    $f->multipleImage('photos')->disk('public')->directory('photos')->maxSize(1024)->max(4)->sortable()->extra(fn ($e) => $e->text('caption'));
    $f->image('avatar');
    $p = $f->toSchema(Request::create('/'));
    expect(json_decode(json_encode($p['values']['photos']), true))->toBe([['path' => 'photos/one.jpg', 'url' => Storage::disk('public')->url('photos/one.jpg'), 'extra' => ['caption' => 'One']]])
        ->and($p['values']['avatar']['path'])->toBe('photos/two.jpg')
        ->and($p['form']['nodes'][0]['field'])->toMatchArray(['maxSize' => 1048576, 'maxFiles' => 4, 'sortable' => true, 'extra' => [['type' => 'text', 'name' => 'caption', 'label' => 'Caption']]]);
});

it('rejects structured or upload extras', function (string $kind) {
    Form::make(User::class)->image('image')->extra(function ($f) use ($kind) {
        match ($kind) {
            'upload' => $f->image('nested'), 'dotted' => $f->text('a.b'), default => $f->section('No', fn ($s) => $s->text('title'))
        };
    });
})->with(['upload', 'section', 'dotted'])->throws(InvalidArgumentException::class);

it('rejects a structure inside a row', function () {
    Form::make(User::class)->row(fn ($r) => $r->section('No', fn ($s) => $s->text('a')));
})->throws(InvalidArgumentException::class);

it('uses explicit page metadata and evaluates readonly for create and edit', function () {
    $f = Form::make(User::class)->name('User')->title('Custom')->description('Details')->layout('horizontal')->presentation('page')->size('sm')->submitTo('PUT', '/users/8')->recordLabel(fn ($r) => $r->name);
    $field = $f->text('name')->readOnly(fn ($r) => $r?->name === 'Locked');
    expect($field->toSchema(null))->not->toHaveKey('readOnly');
    $f->forRecord((new User)->forceFill(['id' => 8, 'name' => 'Locked']));
    $p = $f->toSchema(Request::create('/users/8'));
    expect($p)->toMatchArray(['title' => 'Custom', 'description' => 'Details', 'presentation' => 'page', 'size' => 'sm', 'mode' => 'edit', 'record' => ['id' => 8, 'label' => 'Locked']]);
    expect($p['form']['nodes'][0]['field']['readOnly'])->toBeTrue();
});

it('drops archived uploads whose storage URL has an unsafe scheme', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('url')->with('one.jpg')->once()->andReturn('javascript:alert(1)');
    Storage::shouldReceive('disk')->with('unsafe')->once()->andReturn($disk);
    $r = (new User)->forceFill(['avatar' => 'one.jpg']);
    $f = Form::make(User::class)->forRecord($r)->submitTo('put', '/');
    $f->id('unsafe')->image('avatar')->disk('unsafe');
    expect($f->toSchema(Request::create('/'))['values']['avatar'])->toBeNull();
});

it('rejects scalar fields that would replace an embedded object', function () {
    $form = Form::make(User::class);
    $form->embeds('meta', 'Meta', fn ($e) => null);
    $form->text('meta');
})->throws(InvalidArgumentException::class);

it('rolls back registrations when constructing a container fails', function () {
    $form = Form::make(User::class)->submitTo('post', '/');
    try {
        $form->section('Broken', function ($f) {
            $f->text('name');
            $f->embeds('meta', 'Meta', fn ($e) => $e->text('color'));
            throw new RuntimeException('failed');
        });
    } catch (RuntimeException) {
    }
    $form->text('name')->default('Good');
    expect($form->toSchema(Request::create('/'))['values'])->toBe(['name' => 'Good']);
});

it('rejects an empty embedded object beneath a scalar field', function () {
    $form = Form::make(User::class);
    $form->text('meta');
    $form->embeds('meta.design', 'Design', fn ($e) => null);
})->throws(InvalidArgumentException::class);

it('encodes numerically named root keys as an object', function () {
    $form = Form::make(User::class)->submitTo('post', '/');
    $form->text('0')->default('zero');
    expect(json_encode($form->toSchema(Request::create('/'))['values']))->toBe('{"0":"zero"}');
});

it('preserves numeric-key embeds as objects while retaining list-valued fields', function () {
    $form = Form::make(User::class)->submitTo('post', '/');
    $form->embeds('meta', 'Meta', function ($meta) {
        $meta->text('0')->default('zero');
        $meta->tags('tags')->default(['one', 'two']);
        $meta->embeds('nested', 'Nested', fn ($nested) => $nested->text('0')->default('nested zero'));
    });
    $form->embeds('numeric', 'Numeric', fn ($numeric) => $numeric->text('0')->default('only zero'));
    $values = json_decode(json_encode($form->toSchema(Request::create('/'))['values']));
    expect($values->meta)->toBeInstanceOf(stdClass::class)
        ->and($values->meta->{'0'})->toBe('zero')
        ->and($values->meta->tags)->toBe(['one', 'two'])
        ->and($values->meta->nested)->toBeInstanceOf(stdClass::class)
        ->and($values->meta->nested->{'0'})->toBe('nested zero')
        ->and($values->numeric)->toBeInstanceOf(stdClass::class)
        ->and($values->numeric->{'0'})->toBe('only zero');
});

it('emits numeric-key archived upload extras as UploadValue objects', function () {
    Storage::fake('public');
    $record = new User;
    $record->mergeCasts(['photos' => 'array']);
    $record->forceFill(['photos' => [['path' => 'photos/one.jpg', 'extra' => ['0' => 'zero', 'tags' => ['one', 'two']]], ['path' => 'photos/two.jpg', 'extra' => ['0' => 'only zero']]]]);
    $form = Form::make(User::class)->forRecord($record)->submitTo('put', '/');
    $form->id('photos');
    $form->multipleImage('photos')->extra(function ($extra) {
        $extra->text('0');
        $extra->tags('tags');
    });
    $form->image('single')->attribute('photos.1')->extra(fn ($extra) => $extra->text('0'));
    $values = json_decode(json_encode($form->toSchema(Request::create('/'))['values']));
    foreach ([...$values->photos, $values->single] as $upload) {
        expect(JsonSchema::errors($upload, 'UploadValue'))->toBe([]);
    }
    expect($values->photos)->toBeArray()
        ->and($values->photos[0]->extra)->toBeInstanceOf(stdClass::class)
        ->and($values->photos[0]->extra->{'0'})->toBe('zero')
        ->and($values->photos[0]->extra->tags)->toBe(['one', 'two'])
        ->and($values->single->extra)->toBeInstanceOf(stdClass::class)
        ->and($values->single->extra->{'0'})->toBe('only zero');
});
