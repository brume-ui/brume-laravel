<?php

use Brume\Form\ClientRules;
use Brume\Form\ConditionPath;
use Brume\Form\Conditions;
use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Form\FormValidator;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Options\OptionContext;
use Brume\Options\OptionSource;
use Brume\Tests\Support\JsonSchema;
use Brume\Tests\Support\Models\CellItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

it('rejects noncanonical raw decimal grammar before persistence', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada', 'note' => '1.00']);
    $form = Form::make(CellItem::class)->urls(fn () => ['edit' => '/cells/1']);
    $form->decimal('note')->decimals(2);
    expect(fn () => (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['note' => '.5']])))->toThrow(ValidationException::class);
    expect($record->fresh()->note)->toBe('1.00');
});

it('exports strict decimal grammar and scale with the server message', function ($value) {
    $form = Form::make(CellItem::class);
    $field = $form->decimal('note', 'Amount')->decimals(2);
    try {
        FormValidator::validate($form, ['note' => $value]);
        test()->fail('Expected invalid decimal');
    } catch (ValidationException $e) {
        $rule = collect(ClientRules::for($field, 'note'))->firstWhere('rule', 'pattern');
        expect($rule)->not->toBeNull();
        expect(preg_match('~'.$rule['pattern'].'~D', $value))->toBe(0)
            ->and($rule['message'])->toBe($e->errors()['note'][0]);
    }
})->with(['.5', '1.001']);

it('maps a custom object to declared attributes without a carrier column', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada', 'note' => 'old']);
    $form = Form::make(CellItem::class)->submitTo('put', '/cells/1')->urls(fn () => ['edit' => '/cells/1']);
    $field = $form->component('address', 'Address', 'TaiwanAddress')->attributes(['zipcode' => 'customer', 'county' => 'status', 'district' => 'note', 'address' => 'password']);
    $value = ['zipcode' => '100', 'county' => 'Taipei', 'district' => 'Zhongzheng', 'address' => 'Road'];
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['address' => $value]]));
    expect($record->fresh()->customer)->toBe('100')->and($record->fresh()->status)->toBe('Taipei')->and($record->fresh()->note)->toBe('Zhongzheng');
    expect($field->valueFor($record->fresh()))->toBe(array_replace($value, ['address' => null])); // Hidden values must never be disclosed.
});

it('rejects overlapping mapped attributes and unknown composite keys', function () {
    $form = Form::make(CellItem::class);
    $form->component('address', 'Address', 'TaiwanAddress')->attributes(['zipcode' => 'customer']);
    expect(fn () => FormValidator::validate($form, ['address' => ['zipcode' => '100', 'admin' => true]]))->toThrow(ValidationException::class);
    $form->text('customer');
    expect(fn () => FormValidator::validate($form, []))->toThrow(InvalidArgumentException::class);
});

it('evaluates the unchanged shared condition fixtures', function () {
    $fixtures = json_decode(file_get_contents(__DIR__.'/../../fixtures/conditions/evaluation.json'), true);
    foreach ($fixtures as $fixture) {
        expect(Conditions::fromArray($fixture['condition'])->evaluate($fixture['values'], $fixture['rowPath'] ?? null, $fixture['fields'] ?? [], $fixture['invalidPaths'] ?? []), $fixture['name'])->toBe($fixture['expected']);
    }
});

it('uses authoritative readonly controls and strips inactive forged values', function () {
    $record = new CellItem(['status' => 'open']);
    $form = Form::make(CellItem::class);
    $form->text('status')->readOnly();
    $form->text('customer')->required()->showWhen(Conditions::eq(ConditionPath::root('status'), 'paid'));
    expect(FormValidator::validate($form, ['status' => 'paid', 'customer' => 'forged'], $record))->toBe([]);
});

it('declares recursive JSON repeaters and validates scoped row conditions', function () {
    $form = Form::make(CellItem::class)->submitTo('post', '/cells');
    $form->repeater('tags', 'Rows', function (Form $row) {
        $row->switch('enabled');
        $row->text('name')->required()->showWhen(Conditions::eq(ConditionPath::row('enabled'), true));
    })->min(1)->max(3)->distinct('name');
    $values = ['tags' => [['_brume' => ['key' => 'one'], 'enabled' => false, 'name' => 'forged'], ['_brume' => ['key' => 'two'], 'enabled' => true, 'name' => 'ok']]];
    expect(FormValidator::validate($form, $values))->toBe(['tags' => [['_brume' => ['key' => 'one'], 'enabled' => false], ['_brume' => ['key' => 'two'], 'enabled' => true, 'name' => 'ok']]])
        ->and(JsonSchema::errors(['schemaVersion' => '0.4.0', 'page' => $form->toSchema(Request::create('/'))], 'PageEnvelope'))->toBe([]);
});

it('declares real server option sources without executing a query or inventing an endpoint', function () {
    $source = OptionSource::make(CellItem::class)->label('customer')->search(['customer'])->columns(['customer' => 'Name'])->sections(['open' => 'Open'], fn ($q, $section, $context) => $q->where('status', $section));
    expect($source->configuration()['label'])->toBe('customer');
    $context = new OptionContext(null, ['type' => 'x'], []);
    expect($context->record)->toBeNull()->and($context->dependencies)->toBe(['type' => 'x']);
    $form = Form::make(CellItem::class);
    $field = $form->select('status')->remote($source, ['type' => ConditionPath::root('customer')]);
    expect($field->optionSource())->toBe($source);
    expect(fn () => $field->toSchema())->toThrow(LogicException::class, 'endpoint');
});

it('fails cycles unknown dependencies and protected controlling attributes early', function () {
    $form = Form::make(CellItem::class);
    $form->text('a')->showWhen(Conditions::empty(ConditionPath::root('b')));
    $form->text('b')->enableWhen(Conditions::empty(ConditionPath::root('a')));
    expect(fn () => FormValidator::validate($form, []))->toThrow(InvalidArgumentException::class, 'cycle');
    $form = Form::make(CellItem::class);
    $form->text('password')->readOnly();
    $form->text('customer')->showWhen(Conditions::empty(ConditionPath::root('password')));
    expect(fn () => FormValidator::validate($form, [], new CellItem))->toThrow(InvalidArgumentException::class, 'hidden');
});

it('allows symmetric exclusions but rejects duplicate selected values including typed tags', function () {
    $form = Form::make(CellItem::class);
    $form->tags('a')->excludeSelectedIn([ConditionPath::root('b')]);
    $form->tags('b')->excludeSelectedIn([ConditionPath::root('a')]);
    expect(FormValidator::validate($form, ['a' => ['x'], 'b' => ['y']]))->toBe(['a' => ['x'], 'b' => ['y']]);
    expect(fn () => FormValidator::validate($form, ['a' => ['x'], 'b' => ['x']]))->toThrow(ValidationException::class);
});

it('validates inactive controls from stored values and canonicalizes decimal condition values', function () {
    $form = Form::make(CellItem::class);
    $form->currency('note')->decimals(2);
    $form->text('customer')->required()->showWhen(Conditions::eq(ConditionPath::root('note'), '500.00'));
    expect(fn () => FormValidator::validate($form, ['note' => '500']))->toThrow(ValidationException::class);
    expect(fn () => FormValidator::validate($form, ['note' => '500.001']))->toThrow(ValidationException::class);
});

class DeclarativeParent extends CellItem
{
    protected $table = 'cell_items';

    public function children()
    {
        return $this->hasMany(DeclarativeChild::class, 'shop_id');
    }
}
class DeclarativeChild extends CellItem
{
    protected $table = 'cell_items';
}

it('serializes actual scoped row callbacks with stable capabilities and nullable new templates', function () {
    $parent = DeclarativeParent::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root']);
    $locked = $parent->children()->create(['customer' => 'Locked', 'status' => 'locked']);
    $open = $parent->children()->create(['customer' => 'Open', 'status' => 'open']);
    DeclarativeChild::create(['shop_id' => 99999, 'customer' => 'Foreign']);
    $seen = [];
    $form = Form::make(DeclarativeParent::class)->forRecord($parent)->submitTo('put', '/cells/1');
    $form->repeater('children', 'Children', function (Form $row) use (&$seen) {
        $row->text('customer')->readOnly(function ($record) use (&$seen) {
            $seen[] = $record;

            return $record?->status === 'locked';
        })
            ->rules(fn ($record) => $record?->status === 'open' ? ['required'] : []);
    })->relation('children');
    $page = $form->toSchema(Request::create('/'));
    expect(array_column($page['values']['children'], 'customer'))->toBe(['Locked', 'Open']);
    expect($page['form']['rowFields'][0]['row'])->toBe([['path' => 'children', 'key' => 'record:'.$locked->id, 'id' => (string) $locked->id]])
        ->and($page['form']['rowFields'][0]['fields']['customer'])->toBe(['readOnly' => true, 'rules' => []])
        ->and($page['form']['rowFields'][1]['fields']['customer']['rules'][0]['rule'])->toBe('required')
        ->and($seen)->not->toContain($parent);
});

it('keeps deterministic legacy JSON identities and readonly values through reorder and reload', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Root', 'tags' => [['name' => 'A', 'locked' => 'first'], ['name' => 'B', 'locked' => 'second']]]);
    $form = Form::make(CellItem::class)->forRecord($record)->submitTo('put', '/cells/1')->urls(fn () => ['edit' => '/cells/1']);
    $form->repeater('tags', 'Rows', function (Form $row) {
        $row->text('name');
        $row->text('locked')->readOnly(fn ($context) => $context?->customer === 'Root');
    });
    $initial = $form->toSchema(Request::create('/'))['values']['tags'];
    expect($form->toSchema(Request::create('/'))['values']['tags'])->toBe($initial);
    $moved = array_reverse($initial);
    $moved[0]['locked'] = 'forged';
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['tags' => $moved]]));
    $reloaded = $form->forRecord($record->fresh())->toSchema(Request::create('/'))['values']['tags'];
    expect(array_column($reloaded, 'locked'))->toBe(['second', 'first'])
        ->and(array_column($reloaded, '_brume'))->toBe(array_column($moved, '_brume'));
});

it('keeps nested JSON create callbacks null and resolves readonly controls from matched stored rows', function () {
    $contexts = [];
    $form = Form::make(CellItem::class)->urls(fn () => ['edit' => '/cells/1']);
    $form->text('customer');
    $form->number('shop_id');
    $form->repeater('tags', 'Outer', function (Form $row) use (&$contexts) {
        $row->repeater('inner', 'Inner', function (Form $inner) use (&$contexts) {
            $inner->text('locked')->default('yes')->readOnly(function ($record) use (&$contexts) {
                $contexts[] = $record;

                return true;
            });
            $inner->text('name')->required()->showWhen(Conditions::eq(ConditionPath::row('locked'), 'yes'));
        });
    });
    $values = ['customer' => 'New', 'shop_id' => 1, 'tags' => [['_brume' => ['key' => 'outer'], 'inner' => [['_brume' => ['key' => 'inner'], 'locked' => 'no', 'name' => 'Name']]]]];
    $saved = (new FormSaver)->save($form, null, Request::create('/', 'POST', ['values' => $values]));
    expect($contexts)->not->toBeEmpty()->and(array_filter($contexts, fn ($r) => $r !== null))->toBe([]);
    $stored = CellItem::find($saved['id'])->tags;
    expect($stored[0]['inner'][0]['name'])->toBe('Name')->and($stored[0]['inner'][0]['_brume']['key'])->toBe('inner');
});

it('treats a failed controlling rule as conservative activity for the entire AST', function () {
    $form = Form::make(CellItem::class);
    $form->text('status')->required();
    $form->text('customer')->required()->showWhen(Conditions::eq(ConditionPath::root('status'), 'paid'));
    try {
        FormValidator::validate($form, ['status' => '']);
        test()->fail('Expected required failures');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['status', 'customer']);
    }
});

it('canonicalizes valid decimal persistence without changing accepted strict input forms', function ($value, $expected) {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    $form = Form::make(CellItem::class)->urls(fn () => ['edit' => '/cells/1']);
    $form->decimal('note')->decimals(2);
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['note' => $value]]));
    expect($record->fresh()->note)->toBe($expected);
})->with([['0.5', '0.50'], ['+1.', '1.00'], ['-0.0', '0.00'], ['', null], [null, null]]);

it('rejects strict decimal wire gaps even with an application pattern', function ($raw) {
    $form = Form::make(CellItem::class);
    $form->decimal('note')->pattern('.*');
    expect(fn () => FormValidator::validate($form, ['note' => $raw]))->toThrow(ValidationException::class);
})->with(["0.5\n", '.5', '500.001', 0.5]);

it('rejects malformed reserved row metadata and prevents relation containers entering scalar saves', function ($identity) {
    $form = Form::make(CellItem::class);
    $form->repeater('tags', 'Rows', fn (Form $row) => $row->text('name'));
    expect(fn () => FormValidator::validate($form, ['tags' => [['_brume' => $identity, 'name' => 'x']]]))->toThrow(ValidationException::class);
})->with([[null], [['key' => '']], [['key' => 'a', 'id' => '1']], [['key' => 'a', 'admin' => true]]]);

class TaiwanAddressRecord extends Model
{
    protected $table = 'taiwan_addresses';

    protected $guarded = [];

    public $timestamps = false;
}
class TaiwanAddressResource extends Brume\Resource\Resource
{
    protected string $model = TaiwanAddressRecord::class;

    protected function grid(Grid $grid): void
    {
        $grid->column('county');
    }

    protected function form(Form $form): void
    {
        $form->component('address', '地址', 'TaiwanAddress')->attributes(['zipcode' => 'zipcode', 'county' => 'county', 'district' => 'district', 'address' => 'address'])
            ->required()->childRules(['zipcode' => ['required', 'regex:/^[0-9]{3}$/D'], 'county' => ['required', 'string'], 'district' => ['required', 'string'], 'address' => ['required', 'string']]);
    }
}

it('creates edits and returns nested 422 for the exact public TaiwanAddress mapping', function () {
    Schema::create('taiwan_addresses', function ($table) {
        $table->id();
        foreach (['zipcode', 'county', 'district', 'address'] as $attribute) {
            $table->string($attribute);
        }
    });
    $this->actingAs(brumeUser(), 'brume');
    Route::middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeResource('addresses', TaiwanAddressResource::class));
    Route::getRoutes()->refreshNameLookups();
    $value = ['zipcode' => '100', 'county' => '臺北市', 'district' => '中正區', 'address' => '中山南路1號'];
    $response = $this->postJson('/addresses', ['values' => ['address' => $value]])->assertOk();
    $id = $response->json('id');
    expect(TaiwanAddressRecord::find($id)->only(array_keys($value)))->toBe($value);
    $edit = $this->get('/addresses/'.$id.'/edit', ['X-Brume-Form' => '1'])->assertOk();
    expect($edit->json('page.values.address'))->toBe($value)->and(JsonSchema::errors($edit->json(), 'PageEnvelope'))->toBe([]);
    $this->putJson('/addresses/'.$id, ['values' => ['address' => array_replace($value, ['zipcode' => 'x'])]])->assertUnprocessable()->assertJsonValidationErrors('address.zipcode');
    expect(TaiwanAddressRecord::find($id)->zipcode)->toBe('100');
    $changed = array_replace($value, ['address' => '中山南路2號']);
    $this->putJson('/addresses/'.$id, ['values' => ['address' => $changed]])->assertOk();
    expect(TaiwanAddressRecord::find($id)->only(array_keys($value)))->toBe($changed);
});

it('uses global nullable root callbacks for new JSON templates inside related rows', function () {
    $root = DeclarativeParent::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root']);
    $root->children()->create(['customer' => 'Child', 'status' => 'child', 'tags' => [['name' => 'Locked']]]);
    $form = Form::make(DeclarativeParent::class)->forRecord($root)->submitTo('put', '/cells/1');
    $form->repeater('children', 'Children', function (Form $row) {
        $row->repeater('tags', 'JSON', fn (Form $json) => $json->text('name')->readOnly(fn ($record) => $record?->status === 'root'));
    })->relation('children');
    $page = $form->toSchema(Request::create('/'));
    expect($page['form']['nodes'][0]['field']['nodes'][0]['field']['nodes'][0]['field']['readOnly'])->toBeTrue();
    $nested = collect($page['form']['rowFields'])->first(fn ($entry) => count($entry['row']) === 2);
    expect($nested['fields']['name']['readOnly'])->toBeTrue();
});

it('rejects unsupported picker relationship configurations during declaration resolution', function () {
    $source = OptionSource::make(CellItem::class)->label('customer');
    $form = Form::make(DeclarativeParent::class)->submitTo('post', '/parents');
    $picker = $form->relationPicker('selected', 'Selected', $source)->relation('children')->optionsEndpoint('/test-only-options');
    expect(fn () => $form->toSchema(Request::create('/')))->toThrow(InvalidArgumentException::class, 'nullable');
});

it('rejects reserved paths invalid bounds and ambiguous source choices early', function () {
    $form = Form::make(CellItem::class);
    expect(fn () => $form->text('_brume.key'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $form->repeater('rows', 'Rows', fn (Form $row) => $row->text('_brume')))->toThrow(InvalidArgumentException::class);
    $source = OptionSource::make(CellItem::class);
    expect(fn () => $form->relationPicker('products', 'Products', $source)->extra(fn (Form $extra) => $extra->text('id')))->toThrow(InvalidArgumentException::class);
    expect(fn () => $form->select('choice')->remote($source)->options(['x' => 'X']))->toThrow(InvalidArgumentException::class);
});

it('uses ordinary field writers and readers for nested JSON decimal and range values', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    $form = Form::make(CellItem::class)->forRecord($record)->submitTo('put', '/cells/1')->urls(fn () => ['edit' => '/cells/1']);
    $form->repeater('tags', 'Rows', function (Form $row) {
        $row->decimal('amount')->decimals(2);
        $row->dateRange('period', 'Period', 'start', 'end');
    });
    (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => ['tags' => [['_brume' => ['key' => 'one'], 'amount' => '1.2', 'period' => ['start' => '2026-01-01', 'end' => '2026-01-02']]]]]));
    expect($record->fresh()->tags[0]['amount'])->toBe('1.20')->and($record->fresh()->tags[0]['start'])->toBe('2026-01-01');
    $page = $form->forRecord($record->fresh())->toSchema(Request::create('/'));
    expect($page['values']['tags'][0]['period'])->toBe(['start' => '2026-01-01', 'end' => '2026-01-02']);
});

it('serializes source declarations and flattened intermediary picker row metadata', function () {
    $root = DeclarativeParent::create(['shop_id' => 999, 'customer' => 'Root']);
    $selected = CellItem::create(['shop_id' => 999, 'customer' => 'Product']);
    $child = $root->children()->create(['customer' => 'locked', 'status' => (string) $selected->id]);
    $source = OptionSource::make(CellItem::class)->label('customer')->search(['customer'])->depth('amount')->columns(['customer' => 'Product']);
    $form = Form::make(DeclarativeParent::class)->forRecord($root)->submitTo('put', '/parents/1');
    $form->relationPicker('products', 'Products', $source)->relation('children')->associateBy('status')->multiple(false)
        ->extra(fn (Form $extra) => $extra->text('customer')->readOnly(fn ($row) => $row?->customer === 'locked'))
        ->optionsEndpoint('/test-only-source-binding');
    $page = $form->toSchema(Request::create('/'));
    expect($page['values']['products'])->toBe(['id' => (string) $selected->id, 'customer' => 'locked'])
        ->and($page['form']['rowFields'][0]['row'])->toBe([['path' => 'products', 'key' => (string) $selected->id, 'id' => (string) $selected->id]])
        ->and($page['form']['rowFields'][0]['fields']['customer']['readOnly'])->toBeTrue()
        ->and(JsonSchema::errors(['schemaVersion' => '0.4.0', 'page' => $page], 'PageEnvelope'))->toBe([]);
    expect(fn () => FormValidator::validate($form, ['products' => ['id' => (string) $selected->id]], $root))->toThrow(LogicException::class, 'graph planner');
    expect($child->fresh()->customer)->toBe('locked');
});

it('rejects ambiguous duplicate resolved picker contexts in server snapshots', function () {
    $root = DeclarativeParent::create(['shop_id' => 999, 'customer' => 'Root']);
    $root->children()->create(['customer' => 'One', 'status' => '7']);
    $root->children()->create(['customer' => 'Two', 'status' => '7']);
    $form = Form::make(DeclarativeParent::class)->forRecord($root)->submitTo('put', '/parents/1');
    $form->relationPicker('products', 'Products', OptionSource::make(CellItem::class))->relation('children')->associateBy('status')->optionsEndpoint('/test-only-source-binding');
    expect(fn () => $form->toSchema(Request::create('/')))->toThrow(InvalidArgumentException::class, 'Duplicate');
});

it('does not serialize string-length client rules for repeater row counts', function () {
    $form = Form::make(CellItem::class);
    $field = $form->repeater('tags', 'Rows', fn (Form $row) => $row->text('name'))->min(1)->max(3)->required();
    expect(array_column($field->toSchema()['rules'], 'rule'))->toBe(['required']);
});

it('skips inactive row values during distinct checks just like the client walker', function () {
    $record = CellItem::create(['shop_id' => 1, 'customer' => 'Ada', 'tags' => [['enabled' => false, 'name' => 'same'], ['enabled' => false, 'name' => 'same']]]);
    $form = Form::make(CellItem::class)->forRecord($record)->submitTo('put', '/cells/1');
    $form->repeater('tags', 'Rows', function (Form $row) {
        $row->switch('enabled');
        $row->text('name')->showWhen(Conditions::eq(Conditions::row('enabled'), true));
    })->distinct('name');
    $values = $form->toSchema(Request::create('/'))['values'];
    expect(FormValidator::validate($form, $values, $record)['tags'])->toHaveCount(2);
});

it('conservatively activates conditions on malformed collection controls', function ($definition, $value) {
    expect(Conditions::canonical($definition, $value)[0])->toBeFalse();
    expect(Conditions::empty(ConditionPath::root('items'))->evaluate(['items' => $value], null, ['items' => $definition]))->toBeTrue();
})->with([
    'repeater scalar row' => [['type' => 'repeater'], [1]],
    'repeater list row' => [['type' => 'repeater'], [['a', 'b']]],
    'multiple picker numeric identity' => [['type' => 'relation', 'multiple' => true], [['id' => 1]]],
    'single picker blank identity' => [['type' => 'relation', 'multiple' => false], ['id' => '']],
]);

it('keeps legacy switch coercions but rejects them as condition controls', function ($raw) {
    $legacy = Form::make(CellItem::class);
    $legacy->switch('enabled');
    expect(FormValidator::validate($legacy, ['enabled' => $raw]))->toBe(['enabled' => $raw]);
    $readonly = Form::make(CellItem::class);
    $readonly->switch('enabled')->readOnly()->default($raw);
    $readonly->text('customer')->required()->showWhen(Conditions::eq(Conditions::root('enabled'), true));
    expect(FormValidator::validate($readonly, ['enabled' => ! (bool) $raw, 'customer' => 'Ada']))->toBe((bool) $raw ? ['customer' => 'Ada'] : []);
    $form = Form::make(CellItem::class);
    $form->switch('enabled');
    $form->text('customer')->required()->showWhen(Conditions::not(Conditions::eq(Conditions::root('enabled'), false)));
    try {
        FormValidator::validate($form, ['enabled' => $raw]);
        test()->fail('Expected invalid controller and active dependent');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['enabled', 'customer']);
    }
})->with([0, 1, '0', '1']);

it('requires strict switch controls across recursive condition boundaries', function () {
    $form = Form::make(CellItem::class);
    $form->switch('enabled');
    $form->repeater('tags', 'Rows', function (Form $row) {
        $row->switch('active');
        $row->text('name')->required()->showWhen(Conditions::all(
            Conditions::eq(Conditions::root('enabled'), true),
            Conditions::not(Conditions::eq(Conditions::row('active'), false)),
        ));
    });
    expect(fn () => FormValidator::validate($form, ['enabled' => 1, 'tags' => []]))->toThrow(ValidationException::class);
    try {
        FormValidator::validate($form, ['enabled' => true, 'tags' => [['_brume' => ['key' => 'new'], 'active' => 0]]]);
        test()->fail('Expected row errors');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['tags.0.active', 'tags.0.name']);
    }
});
