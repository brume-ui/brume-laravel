<?php

use Brume\BrumeManager;
use Brume\Form\ButtonNode;
use Brume\Form\ClientRules;
use Brume\Form\Conditions;
use Brume\Form\Fields\Range;
use Brume\Form\Fields\Slider;
use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Form\FormValidator;
use Brume\Grid\Actions\Action;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\JsonSchema;
use Brume\Tests\Support\Models\CellItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

class SmPost extends Model
{
    protected $table = 'small_posts';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['blocks' => 'array'];

    public function rows()
    {
        return $this->hasMany(SmRow::class, 'post_id');
    }
}
class SmRow extends Model
{
    protected $table = 'small_rows';

    protected $guarded = [];

    public $timestamps = false;
}

const SM_TIME = '^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$';
const SM_ICON = '^[a-z0-9]+(?:-[a-z0-9]+)*$';

function smForm(?SmPost $record = null): Form
{
    return Form::make(SmPost::class)->id('sm')->forRecord($record)->submitTo('put', '/sm')->urls(fn ($r) => ['edit' => '/sm/'.$r->id]);
}

function smSave(Form $form, ?Model $record, array $values): array
{
    return (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => $values]));
}

function smErrors(Form $form, array $values): array
{
    try {
        FormValidator::validate($form, $values);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

function smNodes(Form $form, ?SmPost $record = null): array
{
    return $form->forRecord($record)->toSchema(Request::create('/'))['form']['nodes'];
}

function smField(Form $form): array
{
    return smNodes($form)[0]['field'];
}

function smOne(string $method, ?Closure $configure = null): array
{
    $form = Form::make(SmPost::class)->submitTo('put', '/sm');
    $field = $form->$method('address', 'Value');
    if ($configure !== null) {
        $configure($field);
    }

    return smField($form);
}

// ---- exact schemas --------------------------------------------------------------------------

it('serializes each new field exactly', function () {
    expect(smOne('ip'))->toBe(['type' => 'ip', 'name' => 'address', 'label' => 'Value'])
        ->and(smOne('ip', fn ($f) => $f->v4()))->toBe(['type' => 'ip', 'name' => 'address', 'label' => 'Value', 'version' => 4])
        ->and(smOne('ip', fn ($f) => $f->v6()))->toMatchArray(['version' => 6]);
    $rate = smOne('rate', fn ($f) => $f->decimals(1)->min(0)->max(100));
    expect($rate)->toBe([
        'rules' => [
            ['rule' => 'numeric', 'message' => __('validation.numeric', ['attribute' => 'Value'])],
            ['rule' => 'min', 'value' => 0, 'message' => __('validation.min.numeric', ['attribute' => 'Value', 'min' => 0])],
            ['rule' => 'max', 'value' => 100, 'message' => __('validation.max.numeric', ['attribute' => 'Value', 'max' => 100])],
            ['rule' => 'pattern', 'pattern' => '^[+-]?[0-9]+(?:\\.[0-9]{0,1})?$(?![\\s\\S])', 'message' => __('validation.regex', ['attribute' => 'Value'])],
        ],
        'type' => 'rate', 'name' => 'address', 'label' => 'Value', 'decimals' => 1, 'min' => 0, 'max' => 100,
    ]);
    expect(smOne('rate'))->toMatchArray(['type' => 'rate', 'decimals' => 0]);
    $slider = smOne('slider', fn ($f) => $f->min(0)->max(1)->step(0.1));
    expect($slider)->toMatchArray(['type' => 'slider', 'min' => 0, 'max' => 1, 'step' => 0.1])
        ->and(smOne('slider'))->toMatchArray(['min' => 0, 'max' => 100, 'step' => 1])
        ->and(smOne('icon'))->toBe(['rules' => [
            ['rule' => 'pattern', 'pattern' => SM_ICON, 'message' => __('validation.regex', ['attribute' => 'Value'])],
            ['rule' => 'maxLength', 'value' => 64, 'message' => __('validation.max.string', ['attribute' => 'Value', 'max' => 64])],
        ], 'type' => 'icon', 'name' => 'address', 'label' => 'Value'])
        ->and(smOne('icon', fn ($f) => $f->icons(['star', 'heart-handshake'])))->toMatchArray(['icons' => ['star', 'heart-handshake']])
        ->and(smOne('time')['rules'][0])->toBe(['rule' => 'pattern', 'pattern' => SM_TIME, 'message' => __('validation.regex', ['attribute' => 'Value'])])
        ->and(smOne('time', fn ($f) => $f->seconds()))->toMatchArray(['type' => 'time', 'seconds' => true])
        ->and(smOne('time', fn ($f) => $f->seconds()->seconds(false)))->not->toHaveKey('seconds');
});

it('serializes range and timeRange exactly', function () {
    $form = Form::make(SmPost::class)->submitTo('put', '/sm');
    $form->range('span', 'Span', 'low', 'high')->decimals(2);
    $form->range('plain', 'Plain', 'low2', 'high2');
    $form->timeRange('hours', 'Hours', 'opens', 'closes')->seconds();
    $nodes = smNodes($form);
    expect($nodes[0]['field'])->toBe(['defaultValue' => ['start' => null, 'end' => null], 'type' => 'range', 'name' => 'span', 'label' => 'Span', 'decimals' => 2])
        ->and($nodes[1]['field']['decimals'])->toBe(0)
        ->and($nodes[2]['field'])->toBe(['defaultValue' => ['start' => null, 'end' => null], 'type' => 'timeRange', 'name' => 'hours', 'label' => 'Hours', 'seconds' => true]);
});

it('emits schemas the vendored schema accepts, on create and edit', function () {
    $form = Form::make(SmPost::class)->id('sm')->name('Post')->submitTo('post', '/sm');
    $form->section('S', function (Form $f) {
        $f->ip('address')->v4()->default('10.0.0.1');
        $f->rate('share')->decimals(2)->min(0)->max(100)->default('12.5');
        $f->slider('level')->min(0)->max(1)->step(0.1)->default(0.3)->required();
        $f->icon('glyph')->icons(['star'])->default('star');
        $f->time('opens')->seconds()->default('09:00');
        $f->time('closes');
        $f->range('span', 'Span', 'low', 'high')->decimals(1)->required();
        $f->timeRange('hours', 'Hours', 'opens_at', 'closes_at');
        $f->button('Docs', '/docs')->newTab();
        $f->button('Home', fn ($r) => 'https://example.com/'.($r?->id ?? 'new'))->secondary();
        $f->block(null, fn (Form $b) => $b->button('In block', '/x'));
        $f->columns(fn ($c) => $c->column(12, fn ($col) => $col->button('In column', '/y')));
        $f->embeds('meta', 'Meta', fn (Form $e) => $e->button('In embeds', '/z'));
    });
    $form->repeater('blocks', 'Blocks', function (Form $row) {
        $row->ip('address');
        $row->slider('level')->min(0)->max(10)->step(2);
        $row->button('Row button', '/r');
    });
    $envelope = fn () => ['schemaVersion' => BrumeManager::SCHEMA_VERSION, 'page' => $form->toSchema(Request::create('/'))];
    expect(JsonSchema::errors($envelope(), 'PageEnvelope'))->toBe([]);
    $form->forRecord(SmPost::create(['address' => '::1', 'share' => '5.50', 'level' => '0.300', 'low' => '1.000', 'high' => '2.000', 'glyph' => 'star', 'opens' => '09:05:00', 'closes' => '17:00:00']));
    expect(JsonSchema::errors($envelope(), 'PageEnvelope'))->toBe([]);
});

// ---- ip -------------------------------------------------------------------------------------

it('agrees with the browser on every ip form', function (string $input, bool $any, bool $v4, bool $v6) {
    foreach ([[null, $any], ['v4', $v4], ['v6', $v6]] as [$version, $valid]) {
        $form = Form::make(SmPost::class);
        $field = $form->ip('address');
        if ($version !== null) {
            $field->$version();
        }
        expect(smErrors($form, ['address' => $input]) === [])->toBe($valid, "{$input} ".($version ?? 'any'));
    }
})->with([
    ['192.168.1.1', true, true, false], ['0.0.0.0', true, true, false], ['255.255.255.255', true, true, false],
    ['::1', true, false, true], ['::', true, false, true], ['2001:db8::8a2e:370:7334', true, false, true],
    ['::ffff:192.0.2.1', true, false, true], ['1:2:3:4:5:6:7:8', true, false, true], ['1:2:3:4:5:6:1.2.3.4', true, false, true],
    ['  10.0.0.1  ', true, true, false], ["\u{00A0}::1\u{3000}", true, false, true],
    ['256.1.1.1', false, false, false], ['01.2.3.4', false, false, false], ['1.2.3', false, false, false], ['1.2.3.4.5', false, false, false],
    ['1::2::3', false, false, false], ['fe80::1%eth0', false, false, false], ['[::1]', false, false, false],
    ['1.2.3.4/24', false, false, false], ['1.2.3.4:80', false, false, false], ['abc', false, false, false], ['1:2:3:4:5:6:7:8:9', false, false, false],
    [':::', false, false, false], ['12345::', false, false, false], ['1.2.3.-4', false, false, false], ['1.2.3.4 5', false, false, false],
]);

it('picks the ip message by version and falls back to the Laravel text', function () {
    foreach ([['ip', null], ['ipv4', 'v4'], ['ipv6', 'v6']] as [$key, $version]) {
        $form = Form::make(SmPost::class);
        $field = $form->ip('address', 'Host');
        if ($version) {
            $field->$version();
        }
        expect(smErrors($form, ['address' => 'nope'])['address'][0])->toBe(__('validation.'.$key, ['attribute' => 'Host']));
    }
});

it('localises the ip messages', function (string $locale) {
    app()->setLocale($locale);
    $form = Form::make(SmPost::class);
    $form->ip('address', 'Host')->v6();
    expect(smErrors($form, ['address' => '1.2.3.4'])['address'][0])->not->toContain('validation.');
})->with(['en', 'zh_TW', 'zh_CN']);

it('stores a trimmed ip and null for blank', function (?string $input, ?string $stored) {
    $post = SmPost::create(['address' => 'old']);
    $form = smForm($post);
    $form->ip('address');
    smSave($form, $post, ['address' => $input]);
    expect($post->fresh()->address)->toBe($stored);
})->with([['  10.0.0.1 ', '10.0.0.1'], ["\u{3000}::1 ", '::1'], [null, null], ['', null], ['   ', null], ["\u{00A0}", null]]);

it('requires an ip when asked and rejects non-strings', function () {
    $form = Form::make(SmPost::class);
    $form->ip('address')->required();
    expect(smErrors($form, ['address' => '  ']))->toHaveKey('address')
        ->and(smErrors($form, ['address' => 5]))->toHaveKey('address')
        ->and(smErrors($form, ['address' => ['1.2.3.4']]))->toHaveKey('address');
});

// ---- rate -----------------------------------------------------------------------------------

it('treats rate as a numeric decimal with opt-in bounds', function () {
    $form = Form::make(SmPost::class);
    $form->rate('share')->decimals(2)->min(0)->max(100);
    $ok = ['0', '12.5', '100', '99.99', '+5', '-0'];
    $bad = ['100.01', '-0.01', 'abc', '1.234', '1,5'];
    expect(array_values(array_filter($ok, fn ($v) => smErrors($form, ['share' => $v]) === [])))->toBe($ok)
        ->and(array_values(array_filter($bad, fn ($v) => smErrors($form, ['share' => $v]) !== [])))->toBe($bad);
    $unbounded = Form::make(SmPost::class);
    $unbounded->rate('share');
    expect(smErrors($unbounded, ['share' => '-5000']))->toBe([])->and(smErrors($unbounded, ['share' => '1.5']))->toHaveKey('share');
});

it('compares rate bounds as numbers, never as string lengths', function () {
    $form = Form::make(SmPost::class);
    $form->rate('share')->min(10)->max(20);
    expect(smErrors($form, ['share' => '9']))->toHaveKey('share')->and(smErrors($form, ['share' => '15']))->toBe([])->and(smErrors($form, ['share' => '21']))->toHaveKey('share');
});

it('stores a rate rounded like decimal and null when blank', function () {
    $post = SmPost::create();
    $form = smForm($post);
    $form->rate('share')->decimals(2);
    smSave($form, $post, ['share' => '12.5']);
    expect($post->fresh()->share)->toEqual('12.50');
    expect($form->toSchema(Request::create('/'))['values']['share'])->toBe('12.50');
    $form = smForm($post);
    $form->rate('share');
    smSave($form, $post, ['share' => null]);
    expect($post->fresh()->share)->toBeNull();
});

// ---- slider ---------------------------------------------------------------------------------

it('accepts grid values without float error and rejects everything else', function (mixed $value, bool $valid) {
    $form = Form::make(SmPost::class);
    $form->slider('level')->min(0)->max(1)->step(0.1);
    expect(smErrors($form, ['level' => $value]) === [])->toBe($valid, json_encode($value));
})->with([
    [0, true], [0.1, true], [0.2, true], [0.3, true], [0.7, true], [1, true], [1.0, true], ['0.3', true], [0.30000000000000004, true],
    [0.35, false], [-1, false], [2, false], [1.1, false], [-0.1, false], ['abc', false], [true, false], [[0.3], false], [0.3000001, false],
]);

it('counts steps from min', function (int|float $value, bool $valid) {
    $form = Form::make(SmPost::class);
    $form->slider('level')->min(1)->max(10)->step(3);
    expect(smErrors($form, ['level' => $value]) === [])->toBe($valid);
})->with([[1, true], [4, true], [7, true], [10, true], [5, false], [0, false], [11, false], [2.5, false]]);

it('checks a negative and a fractional min', function (float $value, bool $valid) {
    $form = Form::make(SmPost::class);
    $form->slider('level')->min(-0.5)->max(0.5)->step(0.25);
    expect(smErrors($form, ['level' => $value]) === [])->toBe($valid);
})->with([[-0.5, true], [-0.25, true], [0.0, true], [0.5, true], [0.1, false], [-0.6, false]]);

it('names the grid in the slider message', function () {
    $form = Form::make(SmPost::class);
    $form->slider('level', 'Level')->min(0)->max(1)->step(0.1);
    expect(smErrors($form, ['level' => 0.35])['level'][0])->toBe(__('brume::form.slider_step', ['attribute' => 'Level', 'step' => 0.1, 'min' => 0]))
        ->and(smErrors($form, ['level' => 2])['level'][0])->toBe(__('validation.max.numeric', ['attribute' => 'Level', 'max' => 1]));
});

it('validates a required slider normally and stores the number', function () {
    $form = Form::make(SmPost::class);
    $form->slider('level')->required();
    expect(smErrors($form, ['level' => null]))->toHaveKey('level')->and(smErrors($form, ['level' => 0]))->toBe([]);
    $post = SmPost::create();
    $form = smForm($post);
    $form->slider('level')->min(0)->max(1)->step(0.1);
    smSave($form, $post, ['level' => '0.3']);
    expect((float) $post->fresh()->level)->toBe(0.3);
    expect($form->toSchema(Request::create('/'))['values']['level'])->toBe(0.3);
    smSave($form, $post, ['level' => null]);
    expect($post->fresh()->level)->toBeNull();
});

it('refuses impossible slider declarations', function (Closure $declare) {
    expect($declare)->toThrow(InvalidArgumentException::class);
})->with([
    'min equals max' => [fn () => Form::make(SmPost::class)->slider('level')->min(5)->max(5)],
    'min above max' => [fn () => Form::make(SmPost::class)->slider('level')->max(5)->min(6)],
    'step zero' => [fn () => Form::make(SmPost::class)->slider('level')->step(0)],
    'step negative' => [fn () => Form::make(SmPost::class)->slider('level')->step(-1)],
    'min above the default max, at render' => [function () {
        $form = Form::make(SmPost::class)->submitTo('put', '/sm');
        $form->slider('level')->min(200);
        smNodes($form);
    }],
]);

it('lets min and max be set in either order', function () {
    expect(Form::make(SmPost::class)->slider('level')->min(200)->max(300)->settings())->toMatchArray(['min' => 200, 'max' => 300])
        ->and(Form::make(SmPost::class)->slider('level')->max(-10)->min(-20)->settings())->toMatchArray(['min' => -20, 'max' => -10]);
});

// ---- range ----------------------------------------------------------------------------------

it('validates range bounds one by one, on the right bound', function (mixed $value, array $keys) {
    $form = Form::make(SmPost::class);
    $form->range('span', 'Span', 'low', 'high')->decimals(1);
    expect(array_keys(smErrors($form, ['span' => $value])))->toBe($keys, json_encode($value));
})->with([
    [['start' => '1'], []], [['end' => '2.5'], []], [['start' => '2', 'end' => '2.0'], []], [['start' => '-1.5', 'end' => '3'], []],
    [[], []], [['start' => null, 'end' => null], []], [['start' => '', 'end' => ''], []], [['start' => '  ', 'end' => '1'], []],
    [['start' => '3', 'end' => '2.9'], ['span.end']], [['start' => '-1', 'end' => '-2'], ['span.end']], [['start' => '10', 'end' => '9'], ['span.end']],
    [['start' => 'abc'], ['span.start']], [['start' => '1', 'end' => '1.25'], ['span.end']], [['start' => '1.25', 'end' => '2'], ['span.start']],
    [['start' => '+1'], ['span.start']], [['start' => '1.'], ['span.start']], [['start' => '.5'], ['span.start']], [['start' => 1], ['span.start']],
    [['start' => 'abc', 'end' => 'x'], ['span.start', 'span.end']], [['start' => '5', 'end' => 'x'], ['span.end']],
    ['wrong', ['span']], [['start' => '1', 'other' => '2'], ['span']],
]);

it('compares range bounds exactly', function (string $start, string $end, bool $valid) {
    $form = Form::make(SmPost::class);
    $form->range('span', 'Span', 'low', 'high')->decimals(20);
    expect(smErrors($form, ['span' => ['start' => $start, 'end' => $end]]) === [])->toBe($valid);
})->with([
    ['0.30000000000000001', '0.3', false], ['0.3', '0.30000000000000001', true], ['9007199254740993', '9007199254740992', false],
    ['0010', '9', false], ['9', '0010', true], ['-0', '0', true], ['0', '-0.0', true], ['-1', '-1.0', true], ['-0.5', '0.5', true], ['0.5', '-0.5', false],
    ['1'.str_repeat('0', 39).'1', '1'.str_repeat('0', 39).'0', false], ['-1'.str_repeat('0', 39).'1', '-1'.str_repeat('0', 39).'2', false],
]);

it('allows no fraction when range decimals is 0', function () {
    $form = Form::make(SmPost::class);
    $form->range('span', 'Span', 'low', 'high');
    expect(smErrors($form, ['span' => ['start' => '1', 'end' => '2']]))->toBe([])->and(smErrors($form, ['span' => ['start' => '1.0']]))->toHaveKey('span.start')
        ->and(Range::compare('1', '2'))->toBe(-1)->and(Range::compare('2', '1'))->toBe(1)->and(Range::compare('1.0', '1'))->toBe(0);
});

it('treats a required range with no bound as missing', function (mixed $value, bool $valid) {
    $form = Form::make(SmPost::class);
    $form->range('span', 'Span', 'low', 'high')->required();
    expect(smErrors($form, ['span' => $value]) === [])->toBe($valid);
    $form = Form::make(SmPost::class);
    $form->timeRange('hours', 'Hours', 'opens', 'closes')->required();
    $times = array_map(fn ($bound) => is_string($bound) && trim($bound) !== '' ? '01:00' : $bound, $value);
    expect(smErrors($form, ['hours' => $times]) === [])->toBe($valid);
})->with([[[], false], [['start' => null, 'end' => null], false], [['start' => '', 'end' => '  '], false], [['start' => '1'], true], [['end' => null, 'start' => '1'], true]]);

it('writes both columns of a range, or both nulls, normalised', function () {
    $post = SmPost::create(['low' => '9.000', 'high' => '9.000']);
    $form = smForm($post);
    $form->range('span', 'Span', 'low', 'high')->decimals(1);
    smSave($form, $post, ['span' => ['start' => '1', 'end' => '2.5']]);
    expect($post->fresh()->only(['low', 'high']))->toEqual(['low' => '1.000', 'high' => '2.500']);
    expect($form->toSchema(Request::create('/'))['values']['span'])->toBe(['start' => '1.0', 'end' => '2.5']);
    smSave($form, $post, ['span' => ['start' => '3']]);
    expect($post->fresh()->only(['low', 'high']))->toEqual(['low' => '3.000', 'high' => null]);
    smSave($form, $post, ['span' => (object) []]);
    expect($post->fresh()->only(['low', 'high']))->toEqual(['low' => null, 'high' => null]);
    smSave($form, $post, ['span' => null]);
    expect($post->fresh()->only(['low', 'high']))->toEqual(['low' => null, 'high' => null]);
    smSave($form, $post, ['span' => ['start' => '  ', 'end' => '7']]);
    expect($post->fresh()->only(['low', 'high']))->toEqual(['low' => null, 'high' => '7.000']);
});

it('saves ranges inside json repeater rows and relation rows', function (string $type, array $value, array $stored) {
    $post = SmPost::create();
    $form = smForm($post);
    $form->repeater('blocks', 'Blocks', fn (Form $row) => $type === 'range' ? $row->range('span', 'Span', 'lo', 'hi')->decimals(1) : $row->timeRange('hours', 'Hours', 'lo', 'hi'));
    $name = $type === 'range' ? 'span' : 'hours';
    smSave($form, $post, ['blocks' => [['_brume' => ['key' => 'a'], $name => $value]]]);
    expect($post->fresh()->blocks[0])->toMatchArray(['lo' => $stored[0], 'hi' => $stored[1]]);
    $form->forRecord($post->fresh());
    $read = $form->toSchema(Request::create('/'))['values']['blocks'][0][$name];
    expect($read)->toBe(['start' => $type === 'range' ? $stored[0] : substr($stored[0], 0, 5), 'end' => $stored[1] === null ? null : ($type === 'range' ? $stored[1] : substr($stored[1], 0, 5))]);
    $form = smForm($post->fresh());
    $form->repeater('blocks', 'Blocks', fn (Form $row) => $type === 'range' ? $row->range('span', 'Span', 'lo', 'hi')->decimals(1) : $row->timeRange('hours', 'Hours', 'lo', 'hi'));
    smSave($form, $post->fresh(), ['blocks' => [['_brume' => ['key' => 'a'], $name => ['start' => null, 'end' => null]]]]);
    expect($post->fresh()->blocks[0])->toMatchArray(['lo' => null, 'hi' => null]);
})->with([
    ['range', ['start' => '1', 'end' => '2.5'], ['1.0', '2.5']],
    ['range', ['start' => '1'], ['1.0', null]],
    ['timeRange', ['start' => '22:00', 'end' => '06:00'], ['22:00:00', '06:00:00']],
]);

it('saves ranges inside relation rows and reports bound errors with the row path', function () {
    $post = SmPost::create();
    $existing = $post->rows()->create(['low' => '1.000', 'high' => '2.000', 'opens' => '08:00:00']);
    $declare = fn (Form $form) => $form->repeater('rows', 'Rows', function (Form $row) {
        $row->range('span', 'Span', 'low', 'high')->decimals(1);
        $row->timeRange('hours', 'Hours', 'opens', 'closes');
    })->relation('rows');
    $form = smForm($post);
    $declare($form);
    smSave($form, $post, ['rows' => [
        ['_brume' => ['key' => 'record:'.$existing->id, 'id' => (string) $existing->id], 'span' => ['start' => '5', 'end' => '6.5'], 'hours' => ['start' => '09:30', 'end' => null]],
        ['_brume' => ['key' => 'new'], 'span' => ['end' => '3'], 'hours' => ['start' => '22:00', 'end' => '06:00']],
    ]]);
    $rows = $post->rows()->orderBy('id')->get();
    expect($rows[0]->only(['low', 'high', 'opens', 'closes']))->toEqual(['low' => '5.000', 'high' => '6.500', 'opens' => '09:30:00', 'closes' => null])
        ->and($rows[1]->only(['low', 'high', 'opens', 'closes']))->toEqual(['low' => null, 'high' => '3.000', 'opens' => '22:00:00', 'closes' => '06:00:00']);
    $form = smForm($post->fresh());
    $declare($form);
    try {
        smSave($form, $post->fresh(), ['rows' => [['_brume' => ['key' => 'new'], 'span' => ['start' => '5', 'end' => '4'], 'hours' => ['start' => '25:00']]]]);
        test()->fail('Expected validation failure');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['rows.0.span.end', 'rows.0.hours.start']);
    }
});

it('puts range bound errors on the bound, with Laravel messages', function () {
    $form = Form::make(SmPost::class);
    $form->range('span', 'Span', 'low', 'high')->decimals(1);
    $errors = smErrors($form, ['span' => ['start' => '3', 'end' => '2.9']]);
    expect($errors['span.end'][0])->toBe(__('brume::form.range_order'));
    $errors = smErrors($form, ['span' => ['start' => 'abc', 'end' => '1.25']]);
    expect($errors['span.start'][0])->toBe(__('validation.regex', ['attribute' => 'Span']))->and($errors['span.end'][0])->toBe(__('validation.regex', ['attribute' => 'Span']));
    foreach (['en', 'zh_TW', 'zh_CN'] as $locale) {
        app()->setLocale($locale);
        expect(smErrors($form, ['span' => ['start' => '3', 'end' => '2']])['span.end'][0])->not->toContain('brume::');
    }
});

it('does not trust a range that is not a bounds object', function () {
    $form = Form::make(SmPost::class);
    $form->range('span', 'Span', 'low', 'high');
    foreach (['x', 5, [1, 2], ['start' => ['1']]] as $bad) {
        expect(smErrors($form, ['span' => $bad]))->not->toBe([]);
    }
});

// ---- icon -----------------------------------------------------------------------------------

it('accepts and rejects icon names as the browser does', function (string $name, bool $valid) {
    $form = Form::make(SmPost::class);
    $form->icon('glyph');
    expect(smErrors($form, ['glyph' => $name]) === [])->toBe($valid, $name);
    expect((bool) preg_match('/'.SM_ICON.'/uD', $name) && strlen($name) <= 64)->toBe($valid);
})->with([
    ['star', true], ['arrow-down-01', true], ['a-large-small', true], ['no-longer-in-lucide', true], [str_repeat('a', 64), true],
    ['Star', false], ['a_b', false], ['-a', false], ['a--b', false], ['a b', false], [str_repeat('a', 65), false], [' star', false], ["star\n", false], ['star-', false], ['é', false],
]);

it('restricts icons to the declared list and refuses bad declarations', function () {
    $form = Form::make(SmPost::class);
    $form->icon('glyph')->icons(['star', 'heart']);
    expect(smErrors($form, ['glyph' => 'heart']))->toBe([])->and(smErrors($form, ['glyph' => 'cloud']))->toHaveKey('glyph');
    $icons = fn (array $names) => fn () => Form::make(SmPost::class)->icon('glyph')->icons($names);
    expect(Form::make(SmPost::class)->icon('glyph')->icons(array_map(fn ($n) => "i{$n}", range(1, 200)))->settings()['icons'])->toHaveCount(200)
        ->and($icons(array_map(fn ($n) => "i{$n}", range(1, 201))))->toThrow(InvalidArgumentException::class)
        ->and($icons(['Star']))->toThrow(InvalidArgumentException::class)
        ->and($icons(['a_b']))->toThrow(InvalidArgumentException::class)
        ->and($icons([str_repeat('a', 65)]))->toThrow(InvalidArgumentException::class)
        ->and($icons(['ok', 5]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Form::make(SmPost::class)->icon('glyph')->pattern('^x$'))->toThrow(LogicException::class);
});

it('rejects non-string icon values and stores null for blank', function () {
    $form = Form::make(SmPost::class);
    $form->icon('glyph');
    expect(smErrors($form, ['glyph' => 123]))->toHaveKey('glyph')->and(smErrors($form, ['glyph' => ['star']]))->toHaveKey('glyph');
    $post = SmPost::create(['glyph' => 'old-icon']);
    $form = smForm($post);
    $form->icon('glyph');
    smSave($form, $post, ['glyph' => '   ']);
    expect($post->fresh()->glyph)->toBeNull();
    smSave($form, $post, ['glyph' => 'gone-from-lucide']);
    expect($post->fresh()->glyph)->toBe('gone-from-lucide');
});

// ---- time -----------------------------------------------------------------------------------

it('accepts and rejects times as the browser does', function (string $input, bool $valid) {
    foreach ([false, true] as $seconds) {
        $form = Form::make(SmPost::class);
        $form->time('opens')->seconds($seconds);
        expect(smErrors($form, ['opens' => $input]) === [])->toBe($valid, $input);
    }
    expect((bool) preg_match('/'.SM_TIME.'/uD', $input))->toBe($valid);
})->with([
    ['00:00', true], ['09:05', true], ['23:59', true], ['09:05:30', true], ['23:59:59', true],
    ['24:00', false], ['9:5', false], ['09:5', false], ['09:60', false], ['09:05:60', false], ['9:05', false], ['0905', false], ['ab:cd', false], [' 09:05', false], ["09:05\n", false], ['09:05:3', false], ['０９:０５', false],
]);

it('stores HH:mm:ss whatever the field shows, and never shifts a value', function (string $input, bool $seconds, string $stored, string $shown) {
    $post = SmPost::create();
    $form = smForm($post);
    $form->time('opens')->seconds($seconds);
    smSave($form, $post, ['opens' => $input]);
    expect($post->fresh()->opens)->toBe($stored);
    expect($form->toSchema(Request::create('/'))['values']['opens'])->toBe($shown);
})->with([
    ['09:05', false, '09:05:00', '09:05'],
    ['09:05', true, '09:05:00', '09:05:00'],
    ['09:05:00', false, '09:05:00', '09:05'],
    ['09:05:30', true, '09:05:30', '09:05:30'],
    // No seconds on the field: the incoming seconds are kept, and read back whole so the browser's
    // value is the stored value (it shows HH:mm, the stored value changes only on edit).
    ['09:05:30', false, '09:05:30', '09:05:30'],
    ['00:00', false, '00:00:00', '00:00'],
]);

it('stores null for a blank time and reads a stored time back in display form', function () {
    $post = SmPost::create(['opens' => '10:30:00']);
    $form = smForm($post);
    $form->time('opens');
    expect($form->toSchema(Request::create('/'))['values']['opens'])->toBe('10:30');
    smSave($form, $post, ['opens' => '  ']);
    expect($post->fresh()->opens)->toBeNull();
    smSave($form, $post, ['opens' => null]);
    expect($post->fresh()->opens)->toBeNull();
    expect(Form::make(SmPost::class)->time('opens')->default('09:00:00')->toSchema(null)['defaultValue'])->toBe('09:00');
    expect(Form::make(SmPost::class)->time('opens')->seconds()->default('09:00')->toSchema(null)['defaultValue'])->toBe('09:00:00');
});

it('passes an unparseable stored time through rather than hiding it', function () {
    $post = SmPost::create();
    $post->forceFill(['opens' => 'noon'])->saveQuietly();
    $form = smForm($post->fresh());
    $form->time('opens');
    expect($form->toSchema(Request::create('/'))['values']['opens'])->toBe('noon');
});

it('keeps timeRange bounds free of order and normalised', function () {
    $form = Form::make(SmPost::class);
    $form->timeRange('hours', 'Hours', 'opens', 'closes');
    expect(smErrors($form, ['hours' => ['start' => '22:00', 'end' => '06:00']]))->toBe([])
        ->and(array_keys(smErrors($form, ['hours' => ['start' => '25:00']])))->toBe(['hours.start'])
        ->and(array_keys(smErrors($form, ['hours' => ['start' => '22:00', 'end' => '9:5']])))->toBe(['hours.end'])
        ->and(smErrors($form, ['hours' => ['start' => '', 'end' => null]]))->toBe([])
        ->and(smErrors($form, ['hours' => 'x']))->toHaveKey('hours');
    $post = SmPost::create(['opens' => '09:00:00', 'closes' => '17:00:00']);
    $form = smForm($post);
    $form->timeRange('hours', 'Hours', 'opens', 'closes');
    expect($form->toSchema(Request::create('/'))['values']['hours'])->toBe(['start' => '09:00', 'end' => '17:00']);
    smSave($form, $post, ['hours' => ['start' => '22:00', 'end' => '06:00']]);
    expect($post->fresh()->only(['opens', 'closes']))->toBe(['opens' => '22:00:00', 'closes' => '06:00:00']);
    smSave($form, $post, ['hours' => ['start' => '22:00:15']]);
    expect($post->fresh()->only(['opens', 'closes']))->toBe(['opens' => '22:00:15', 'closes' => null]);
    expect($form->toSchema(Request::create('/'))['values']['hours'])->toBe(['start' => '22:00:15', 'end' => null]);
});

it('shows timeRange seconds when the field has them', function () {
    $post = SmPost::create(['opens' => '09:00:00', 'closes' => '17:00:00']);
    $form = smForm($post);
    $form->timeRange('hours', 'Hours', 'opens', 'closes')->seconds();
    expect($form->toSchema(Request::create('/'))['values']['hours'])->toBe(['start' => '09:00:00', 'end' => '17:00:00']);
});

// ---- repeater rows and relation rows -------------------------------------------------------

it('saves the plain new fields inside json repeater rows', function () {
    $post = SmPost::create();
    $form = smForm($post);
    $declare = function (Form $f) {
        $f->repeater('blocks', 'Blocks', function (Form $row) {
            $row->ip('host')->v4();
            $row->rate('share')->decimals(1);
            $row->slider('level')->min(0)->max(1)->step(0.1);
            $row->icon('glyph');
            $row->time('opens');
        });
    };
    $declare($form);
    smSave($form, $post, ['blocks' => [['_brume' => ['key' => 'a'], 'host' => ' 10.0.0.1 ', 'share' => '12.3', 'level' => 0.3, 'glyph' => 'star', 'opens' => '09:05']]]);
    expect($post->fresh()->blocks[0])->toMatchArray(['host' => '10.0.0.1', 'share' => '12.3', 'level' => 0.3, 'glyph' => 'star', 'opens' => '09:05:00']);
    foreach ([['host' => '::1'], ['level' => 0.35], ['glyph' => 'Bad'], ['opens' => '24:00'], ['share' => 'x']] as $bad) {
        $form = smForm($post->fresh());
        $declare($form);
        expect(fn () => smSave($form, $post, ['blocks' => [['_brume' => ['key' => 'a']] + $bad]]))->toThrow(ValidationException::class);
    }
});

it('saves the plain new fields inside relation rows', function () {
    $post = SmPost::create();
    $row = $post->rows()->create(['address' => '1.1.1.1']);
    $form = smForm($post);
    $form->repeater('rows', 'Rows', function (Form $r) {
        $r->ip('address');
        $r->time('opens');
    })->relation('rows');
    smSave($form, $post, ['rows' => [
        ['_brume' => ['key' => 'record:'.$row->id, 'id' => (string) $row->id], 'address' => '2001:db8::1', 'opens' => '07:30'],
        ['_brume' => ['key' => 'new'], 'address' => ' 8.8.8.8 ', 'opens' => null],
    ]]);
    expect($post->rows()->orderBy('id')->get(['address', 'opens'])->toArray())->toBe([['address' => '2001:db8::1', 'opens' => '07:30:00'], ['address' => '8.8.8.8', 'opens' => null]]);
});

// ---- conditions, extras, actions ------------------------------------------------------------

it('lets the new fields drive and obey conditions with typed operands', function () {
    $conditions = Conditions::class;
    $make = fn () => [
        'rate' => ['type' => 'rate', 'decimals' => 1], 'slider' => ['type' => 'slider', 'min' => 0, 'max' => 1, 'step' => 0.1],
        'ip' => ['type' => 'ip'], 'range' => ['type' => 'range', 'decimals' => 0],
    ];
    expect($conditions::canonical($make()['rate'], '12.5'))->toBe([true, '12.5'])
        ->and($conditions::canonical($make()['rate'], '1.25')[0])->toBeFalse()
        ->and($conditions::canonical($make()['slider'], '0.3'))->toBe([true, 0.3])
        ->and($conditions::canonical($make()['slider'], 'x')[0])->toBeFalse()
        ->and($conditions::canonical($make()['ip'], '::1'))->toBe([true, '::1'])
        ->and($conditions::canonical($make()['ip'], 5)[0])->toBeFalse()
        ->and($conditions::canonical(['type' => 'icon'], 'star')[0])->toBeTrue()
        ->and($conditions::canonical(['type' => 'time'], '09:00')[0])->toBeTrue()
        ->and($conditions::canonical($make()['range'], ['start' => '1', 'end' => null])[0])->toBeTrue()
        ->and($conditions::canonical($make()['range'], (object) ['start' => '1'])[0])->toBeTrue()
        ->and($conditions::canonical($make()['range'], ['start' => 1])[0])->toBeFalse()
        ->and($conditions::canonical($make()['range'], [1, 2])[0])->toBeFalse();
    $form = Form::make(SmPost::class);
    $form->slider('level')->min(0)->max(1)->step(0.1);
    $form->ip('address')->required()->showWhen(Conditions::eq(Conditions::root('level'), 0.3));
    expect(smErrors($form, ['level' => 0.3, 'address' => null]))->toHaveKey('address')
        ->and(smErrors($form, ['level' => 0.2, 'address' => null]))->toBe([]);
});

it('allows the plain new fields in flat extras and keeps buttons out', function () {
    $form = Form::make(SmPost::class);
    $form->ip('a');
    $form->rate('b');
    $form->slider('c');
    $form->icon('d');
    $form->time('e');
    $form->range('f', 'F', 'x', 'y');
    $form->timeRange('g', 'G', 'x2', 'y2');
    expect($form->plainFields())->toHaveCount(7);
    $form = Form::make(SmPost::class);
    $form->button('B', '/b');
    expect(fn () => $form->plainFields())->toThrow(InvalidArgumentException::class);
});

it('accepts the plain new fields in an action form', function () {
    $this->actingAs(brumeUser(), 'brume');
    $item = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    $seen = (object) ['input' => null];
    Route::middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeGrid('sm-items', function () use ($seen) {
        $grid = Grid::make(CellItem::class);
        $grid->column('customer');
        $grid->rowActions(Action::make('tune', 'Tune')->form(function (Form $form) {
            $form->ip('host')->v4();
            $form->rate('share')->decimals(1);
            $form->slider('level')->min(0)->max(1)->step(0.1)->required();
            $form->icon('glyph');
            $form->time('opens');
            $form->range('span', 'Span', 'a', 'b')->decimals(1);
            $form->timeRange('hours', 'Hours', 'c', 'd');
        })->handle(function ($models, $request, array $input) use ($seen) {
            $seen->input = $input;
        }));

        return $grid;
    }));
    $page = $this->get('/sm-items')->assertOk()->viewData('page')['props']['envelope']['page'];
    expect(array_column($page['actions']['row'][0]['form']['fields'], 'type'))->toBe(['ip', 'rate', 'slider', 'icon', 'time', 'range', 'timeRange']);
    $body = ['target' => 'row', 'ids' => [$item->id], 'input' => ['host' => '::1', 'share' => '1.5', 'level' => 0.35, 'glyph' => 'star', 'opens' => '09:05', 'span' => ['start' => '2', 'end' => '1'], 'hours' => ['start' => '22:00', 'end' => '06:00']]];
    $this->postJson('/sm-items/_brume/actions/tune', $body)->assertUnprocessable()->assertJsonValidationErrors(['host', 'level', 'span.end']);
    $body['input'] = ['host' => ' 10.0.0.1 ', 'share' => '1.5', 'level' => 0.3, 'glyph' => 'star', 'opens' => '09:05', 'span' => ['start' => '1', 'end' => '2'], 'hours' => ['start' => '22:00', 'end' => '06:00']];
    $this->postJson('/sm-items/_brume/actions/tune', $body)->assertOk();
    expect($seen->input)->toMatchArray(['host' => '10.0.0.1', 'share' => '1.5', 'level' => 0.3, 'glyph' => 'star', 'opens' => '09:05:00'])
        ->and($seen->input['span'])->toBe(['start' => '1', 'end' => '2'])
        ->and($seen->input['hours'])->toBe(['start' => '22:00', 'end' => '06:00']);
});

it('passes null for blank action input of the new plain fields', function () {
    $this->actingAs(brumeUser(), 'brume');
    $item = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    $seen = (object) ['input' => null];
    Route::middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeGrid('sm-blank', function () use ($seen) {
        $grid = Grid::make(CellItem::class);
        $grid->column('customer');
        $grid->rowActions(Action::make('tune', 'Tune')->form(function (Form $form) {
            $form->ip('host');
            $form->icon('glyph');
            $form->time('opens');
            $form->slider('level');
        })->handle(function ($models, $request, array $input) use ($seen) {
            $seen->input = $input;
        }));

        return $grid;
    }));
    $this->postJson('/sm-blank/_brume/actions/tune', ['target' => 'row', 'ids' => [$item->id], 'input' => ['host' => '  ', 'glyph' => '   ', 'opens' => ' ', 'level' => '']])->assertOk();
    expect($seen->input)->toBe(['host' => null, 'glyph' => null, 'opens' => null, 'level' => null]);
});

it('hands the client the same validation rules the server applies', function () {
    $form = Form::make(SmPost::class);
    $slider = $form->slider('level', 'Level')->min(0)->max(1)->step(0.1)->required();
    $rules = collect(ClientRules::for($slider, 'level', null))->pluck('rule')->all();
    expect($rules)->toBe(['numeric', 'required', 'min', 'max']);
    $ip = $form->ip('address')->required();
    expect(collect(ClientRules::for($ip, 'address', null))->pluck('rule')->all())->toBe(['required']);
});

// ---- button ---------------------------------------------------------------------------------

it('emits a button node exactly', function () {
    $form = Form::make(SmPost::class)->submitTo('put', '/sm');
    $form->button('Docs', '/docs');
    $form->button('Site', 'https://example.com/a?b=1#c')->newTab()->secondary();
    $form->button('Plain', '/p')->newTab(false)->secondary(false);
    expect(smNodes($form))->toBe([
        ['type' => 'button', 'label' => 'Docs', 'href' => '/docs'],
        ['type' => 'button', 'label' => 'Site', 'href' => 'https://example.com/a?b=1#c', 'newTab' => true, 'variant' => 'secondary'],
        ['type' => 'button', 'label' => 'Plain', 'href' => '/p'],
    ]);
});

it('resolves a button closure with null on create and the record on edit', function () {
    $calls = [];
    $form = smForm();
    $form->button('Go', function (?Model $record) use (&$calls) {
        $calls[] = $record?->getKey();

        return $record === null ? '/new' : '/posts/'.$record->getKey();
    });
    $post = SmPost::create();
    expect(smNodes($form)[0]['href'])->toBe('/new')->and(smNodes($form, $post)[0]['href'])->toBe('/posts/'.$post->id)->and($calls)->toBe([null, $post->id]);
});

it('places buttons wherever a divider or html block may go', function () {
    $form = Form::make(SmPost::class)->submitTo('put', '/sm')->tabs();
    $form->section('One', function (Form $f) {
        $f->button('a', '/a');
        $f->block('B', fn (Form $b) => $b->button('b', '/b'));
        $f->columns(fn ($c) => $c->column(12, fn ($col) => $col->button('c', '/c')));
        $f->embeds('meta', 'Meta', function ($e) {
            $e->button('d', '/d');
            $e->text('x');
        });
    });
    $form->repeater('blocks', 'Blocks', function (Form $row) {
        $row->button('e', '/e');
        $row->text('x');
    });
    $nodes = smNodes($form);
    expect($nodes[0]['children'][0]['type'])->toBe('button')->and($nodes[0]['children'][1]['children'][0]['type'])->toBe('button')
        ->and($nodes[0]['children'][2]['columns'][0]['children'][0]['type'])->toBe('button')->and($nodes[0]['children'][3]['children'][0]['type'])->toBe('button')
        ->and($nodes[1]['field']['nodes'][0]['type'])->toBe('button')
        ->and(array_keys($form->fields()))->toBe(['meta.x', 'blocks']);
    expect(fn () => Form::make(SmPost::class)->row(fn ($r) => $r->button('x', '/x')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Form::make(SmPost::class)->columns(fn ($c) => $c->button('x', '/x')))->toThrow(InvalidArgumentException::class);
});

it('accepts and refuses button urls as the renderer does', function (string $url, bool $valid) {
    expect(ButtonNode::isHref($url))->toBe($valid, $url);
    $form = Form::make(SmPost::class)->submitTo('put', '/sm');
    $form->button('B', $url);
    $form->button('C', fn () => $url);
    if ($valid) {
        expect(smNodes($form)[1]['href'])->toBe($url);
    } else {
        expect(fn () => smNodes($form))->toThrow(InvalidArgumentException::class);
        $form = Form::make(SmPost::class)->submitTo('put', '/sm');
        $form->button('C', fn () => $url);
        // A closure result degrades instead (see the dedicated test).
        Exceptions::fake();
        expect(smNodes($form))->toBe([]);
    }
})->with([
    ['/docs', true], ['/', true], ['/a/b?x=1#y', true], ['http://example.com', true], ['https://example.com/path', true], ['HTTPS://Example.com', true],
    ['http://localhost:8000/x', true], ['http://[::1]:8000/', true], ['https://user:pw@example.com/', true], ['http://127.0.0.1', true],
    ['//evil.example', false], ['/\\evil.example', false], ['\\\\evil', false], ['mailto:a@b.c', false], ['tel:+886', false], ['javascript:alert(1)', false],
    ['JaVaScRiPt:alert(1)', false], ['data:text/html,x', false], ['relative/path', false], ['', false], ['#top', false], ['?x=1', false],
    ['/with space', false], ["/tab\t", false], ["/new\nline", false], [' /lead', false], ['/trail ', false], ["/nul\0", false], ["/del\x7f", false],
    ['https://exa mple.com', false], ['https://', false], ['http:///x', false], ['http://?x', false], ['https://:80/', false], ['http://a<b/', false], ['http://a%b/', false],
    ['ftp://example.com', false], ['https:/example.com', false], ['https:example.com', false],
]);

it('refuses a button closure that does not return a string', function () {
    $form = Form::make(SmPost::class)->submitTo('put', '/sm');
    $form->button('B', fn () => null);
    Exceptions::fake();
    expect(smNodes($form))->toBe([]);
});

it('keeps buttons out of validation, fields and saving', function () {
    $post = SmPost::create();
    $form = smForm($post);
    $form->button('B', '/b');
    $form->ip('address');
    expect(array_keys($form->fields()))->toBe(['address'])->and(smErrors($form, ['address' => '::1']))->toBe([]);
    smSave($form, $post, ['address' => '::1']);
    expect($post->fresh()->address)->toBe('::1')->and($form->toSchema(Request::create('/'))['values'])->toBe(['address' => '::1']);
});

it('survives the declaration copy a lifecycle hook makes', function () {
    $post = SmPost::create();
    $form = smForm($post);
    $form->editing(fn () => null);
    $form->button('B', fn ($r) => '/p/'.$r?->id)->newTab();
    $form->ip('address');
    expect(smNodes($form, $post)[0])->toBe(['type' => 'button', 'label' => 'B', 'href' => '/p/'.$post->id, 'newTab' => true]);
    $copy = clone $form;
    expect(smNodes($copy, $post)[0]['newTab'])->toBeTrue();
});

it('shares the slider grid maths with the browser', function () {
    foreach ([[0.3, 0, 0.1, true], [0.35, 0, 0.1, false], [0.7, 0, 0.1, true], [1.0, 0, 0.1, true], [15.0, 5, 5, true], [12.0, 5, 5, false], [-0.25, -0.5, 0.25, true], [0.015, 0, 0.005, true], [0.0151, 0, 0.005, false], [1e7 + 0.3, 0, 0.1, true]] as [$value, $min, $step, $valid]) {
        expect(Slider::onGrid($value, $min, $step))->toBe($valid, "{$value} min {$min} step {$step}");
    }
});

it('leaves out a button whose closure url is unusable and reports it', function (mixed $url) {
    Exceptions::fake();
    $form = smForm();
    $form->section('S', function (Form $f) use ($url) {
        $f->button('Bad', fn () => $url);
        $f->block(null, fn (Form $b) => $b->button('Only', fn () => $url));
    });
    $form->button('Top', fn () => $url);
    $form->ip('address');
    $page = $form->toSchema(Request::create('/'));
    expect(JsonSchema::errors(['schemaVersion' => BrumeManager::SCHEMA_VERSION, 'page' => $page], 'PageEnvelope'))->toBe([])
        ->and($page['form']['nodes'])->toHaveCount(2)
        ->and($page['form']['nodes'][0]['children'])->toBe([['type' => 'block', 'children' => []]])
        ->and($page['form']['nodes'][1]['field']['name'])->toBe('address');
    Exceptions::assertReported(fn (InvalidArgumentException $e) => str_contains($e->getMessage(), '"Bad"'));
})->with(['javascript:x', '', null, '//evil.example', 5]);

it('still throws for a literal bad button url', function () {
    $form = smForm();
    $form->button('Bad', 'javascript:x');
    expect(fn () => smNodes($form))->toThrow(InvalidArgumentException::class);
});

it('treats unicode whitespace as an empty value', function (string $blank) {
    $post = SmPost::create(['address' => 'x', 'glyph' => 'x', 'opens' => '01:00:00', 'level' => 5, 'share' => '5']);
    $form = smForm($post);
    $form->ip('address');
    $form->icon('glyph');
    $form->time('opens');
    $form->slider('level');
    $form->rate('share');
    $form->range('span', 'Span', 'low', 'high');
    smSave($form, $post, ['address' => $blank, 'glyph' => $blank, 'opens' => $blank, 'level' => $blank, 'share' => $blank, 'span' => ['start' => $blank, 'end' => '3']]);
    $fresh = $post->fresh();
    expect($fresh->only(['address', 'glyph', 'opens', 'level', 'share', 'low', 'high']))->toEqual(['address' => null, 'glyph' => null, 'opens' => null, 'level' => null, 'share' => null, 'low' => null, 'high' => '3.000']);
    $post = SmPost::create(['opens' => '01:00:00', 'closes' => '02:00:00']);
    $form = smForm($post);
    $form->timeRange('hours', 'Hours', 'opens', 'closes');
    smSave($form, $post, ['hours' => ['start' => $blank, 'end' => '03:00']]);
    expect($post->fresh()->only(['opens', 'closes']))->toBe(['opens' => null, 'closes' => '03:00:00']);
})->with(["\u{00A0}", "\u{3000}", "\u{FEFF}", " \u{00A0}\t"]);

it('fails a required field given only unicode whitespace with the required message', function (string $method) {
    $form = Form::make(SmPost::class);
    $form->$method('v', 'V')->required();
    expect(smErrors($form, ['v' => "\u{00A0}\u{3000}"])['v'][0])->toBe(__('validation.required', ['attribute' => 'V']));
})->with(['ip', 'icon', 'time', 'slider', 'rate']);

it('fails a required range given only unicode whitespace bounds', function () {
    $form = Form::make(SmPost::class);
    $form->range('v', 'V', 'low', 'high')->required();
    expect(smErrors($form, ['v' => ['start' => "\u{00A0}", 'end' => "\u{3000}"]]))->toHaveKey('v');
});
