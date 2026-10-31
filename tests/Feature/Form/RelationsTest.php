<?php

use Brume\Form\Conditions;
use Brume\Form\DeclarationRegistry;
use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Form\Relations\RelationPlanner;
use Brume\Form\Relations\RelationSynchronizer;
use Brume\Options\FormOptionContext;
use Brume\Options\OptionSource;
use Brume\Tests\Support\Models\CodeItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GraphRoot extends Model
{
    protected $table = 'graph_roots';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['data' => 'array', 'codes' => 'array'];

    public function children()
    {
        return $this->hasMany(GraphChild::class, 'root_id');
    }

    public function products()
    {
        return $this->hasMany(GraphProduct::class, 'root_id');
    }

    public function gifts()
    {
        return $this->hasMany(GraphGift::class, 'root_id');
    }

    public function owner()
    {
        return $this->belongsTo(GraphProduct::class, 'owner_id');
    }

    public function components()
    {
        return $this->belongsToMany(GraphProduct::class, 'graph_components', 'root_id', 'product_id')->withPivot(['quantity', 'sold']);
    }
}
class GraphChild extends Model
{
    protected $table = 'graph_children';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['data' => 'array'];

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function foreignChildren()
    {
        return $this->hasMany(GraphForeignChild::class, 'parent_id');
    }
}
class GraphForeignChild extends GraphChild
{
    protected $connection = 'other';
}
class GraphProduct extends Model
{
    protected $table = 'graph_products';

    protected $guarded = [];

    public $timestamps = false;
}
class GraphGift extends Model
{
    use SoftDeletes;

    protected $table = 'graph_gifts';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    Schema::create('graph_roots', function ($t) {
        $t->id();
        $t->string('name')->default('root');
        $t->unsignedBigInteger('owner_id')->nullable();
        $t->integer('sold')->default(0);
        $t->string('code')->nullable();
        $t->json('codes')->nullable();
        $t->json('data')->nullable();
    });
    Schema::create('graph_children', function ($t) {
        $t->id();
        $t->unsignedBigInteger('root_id')->nullable();
        $t->unsignedBigInteger('parent_id')->nullable();
        $t->string('name')->default('child');
        $t->integer('sold')->default(0);
        $t->integer('position')->default(0);
        $t->json('data')->nullable();
    });
    Schema::create('graph_products', function ($t) {
        $t->id();
        $t->unsignedBigInteger('root_id')->nullable();
        $t->string('name')->default('product');
        $t->integer('tenant')->default(1);
        $t->boolean('active')->default(true);
    });
    Schema::create('graph_gifts', function ($t) {
        $t->id();
        $t->unsignedBigInteger('root_id');
        $t->unsignedBigInteger('product_id');
        $t->integer('quantity')->default(1);
        $t->integer('sold')->default(0);
        $t->softDeletes();
    });
    Schema::create('graph_components', function ($t) {
        $t->unsignedBigInteger('root_id');
        $t->unsignedBigInteger('product_id');
        $t->integer('quantity')->default(1);
        $t->integer('sold')->default(0);
        $t->primary(['root_id', 'product_id']);
    });
});
function graphForm(): Form
{
    return Form::make(GraphRoot::class)->urls(fn ($r) => ['edit' => '/roots/'.$r->id]);
}
function graphSource(): OptionSource
{
    return OptionSource::make(fn () => GraphProduct::where('tenant', 1)->where('active', true))->label('name');
}
function graphSave(Form $form, ?Model $root, array $values): array
{
    return (new FormSaver)->save($form, $root, Request::create('/', 'PUT', ['values' => $values]));
}
function graphRow(Model $row, array $values = []): array
{
    return ['_brume' => ['key' => 'record:'.$row->id, 'id' => (string) $row->id]] + $values;
}
function graphTree(Form $form, int $depth): void
{
    $form->text('name')->required();
    if ($depth > 0) {
        $form->repeater('children', 'Children', fn ($row) => graphTree($row, $depth - 1))->relation('children')->orderColumn('position');
    }
}

it('belongsTo assigns clears and rejects scoped foreign ids', function () {
    $root = GraphRoot::create();
    $owned = GraphProduct::create();
    $foreign = GraphProduct::create(['tenant' => 2]);
    $form = graphForm();
    $form->relationPicker('owner', 'Owner', graphSource())->relation('owner')->multiple(false);
    graphSave($form, $root, ['owner' => ['id' => (string) $owned->id]]);
    expect($root->fresh()->owner_id)->toBe($owned->id);
    expect($form->fields()['owner']->valueFor($root->fresh()))->toBe(['id' => (string) $owned->id]);
    expect(fn () => graphSave($form, $root, ['owner' => ['id' => (string) $foreign->id]]))->toThrow(ValidationException::class);
    expect($root->fresh()->owner_id)->toBe($owned->id);
    graphSave($form, $root, ['owner' => null]);
    expect($root->fresh()->owner_id)->toBeNull();
});

it('direct hasMany reassigns and nulls removed products', function () {
    $root = GraphRoot::create();
    $other = GraphRoot::create();
    $old = GraphProduct::create(['root_id' => $root->id]);
    $new = GraphProduct::create(['root_id' => $other->id]);
    $form = graphForm();
    $form->relationPicker('products', 'Products', graphSource())->relation('products');
    graphSave($form, $root, ['products' => [['id' => (string) $new->id]]]);
    expect($new->fresh()->root_id)->toBe($root->id)->and($old->fresh()->root_id)->toBeNull();
    graphSave($form, $root, []);
    expect($new->fresh()->root_id)->toBe($root->id);
    graphSave($form, $root, ['products' => []]);
    expect($new->fresh()->root_id)->toBeNull();
});

it('belongsToMany preserves selected pivot quantities and counters', function () {
    $root = GraphRoot::create();
    $one = GraphProduct::create();
    $two = GraphProduct::create();
    $root->components()->attach($one, ['quantity' => 3, 'sold' => 9]);
    $seen = [];
    $form = graphForm();
    $field = $form->relationPicker('components', 'Components', graphSource())->relation('components')->extra(function ($row) use (&$seen) {
        $row->number('quantity')->required()->readOnly(function ($record) use (&$seen) {
            $seen[] = $record;

            return false;
        });
    });
    expect($field->valueFor($root)[0]['quantity'])->toBe(3);
    graphSave($form, $root, ['components' => [['id' => (string) $one->id, 'quantity' => 7], ['id' => (string) $two->id, 'quantity' => 2]]]);
    expect($root->components()->find($one->id)->pivot->quantity)->toBe(7)->and($root->components()->find($one->id)->pivot->sold)->toBe(9)
        ->and($root->components()->find($two->id)->pivot->quantity)->toBe(2);
    expect(collect($seen)->filter()->every(fn ($record) => $record instanceof Pivot))->toBeTrue();
    expect(in_array(null, $seen, true))->toBeTrue();
});

it('intermediary restore preserves identity and counters', function () {
    $root = GraphRoot::create();
    $product = GraphProduct::create();
    $gift = GraphGift::create(['root_id' => $root->id, 'product_id' => $product->id, 'sold' => 17]);
    $form = graphForm();
    $form->relationPicker('gifts', 'Gifts', graphSource())->relation('gifts')->associateBy('product_id')->restoreRemoved()->extra(fn ($row) => $row->number('quantity')->required());
    graphSave($form, $root, ['gifts' => []]);
    expect($gift->fresh()->trashed())->toBeTrue();
    graphSave($form, $root, ['gifts' => [['id' => (string) $product->id, 'quantity' => 4, 'sold' => 0]]]);
    expect($gift->fresh()->trashed())->toBeFalse()->and($gift->fresh()->sold)->toBe(17)->and($gift->fresh()->quantity)->toBe(4)->and(GraphGift::withTrashed()->count())->toBe(1);
});

it('ambiguous historical association rejects atomically', function () {
    $root = GraphRoot::create();
    $product = GraphProduct::create();
    foreach ([1, 2] as $unused) {
        $gift = GraphGift::create(['root_id' => $root->id, 'product_id' => $product->id]);
        $gift->delete();
    }
    $form = graphForm();
    $form->text('name');
    $form->relationPicker('gifts', 'Gifts', graphSource())->relation('gifts')->associateBy('product_id')->restoreRemoved();
    expect(fn () => graphSave($form, $root, ['name' => 'changed', 'gifts' => [['id' => (string) $product->id]]]))->toThrow(ValidationException::class);
    expect($root->fresh()->name)->toBe('root')->and(GraphGift::count())->toBe(0);
});

it('four level repeater updates in place and orders on create and edit', function () {
    $form = graphForm();
    graphTree($form, 4);
    $leaf = ['_brume' => ['key' => 'leaf'], 'name' => 'four'];
    $values = $leaf;
    for ($n = 3; $n >= 1; $n--) {
        $values = ['_brume' => ['key' => 'level'.$n], 'name' => 'level'.$n, 'children' => [$values]];
    }
    $response = graphSave($form, null, ['name' => 'created', 'children' => [$values]]);
    $root = GraphRoot::findOrFail($response['id']);
    $one = $root->children()->first();
    $two = $one->children()->first();
    $three = $two->children()->first();
    $four = $three->children()->first();
    $four->update(['sold' => 19]);
    $sibling = $three->children()->create(['name' => 'sibling', 'position' => 1]);
    $edited = graphRow($three, ['name' => 'three', 'children' => [graphRow($sibling, ['name' => 'first']), graphRow($four, ['name' => 'last'])]]);
    $edited = graphRow($two, ['name' => 'two', 'children' => [$edited]]);
    $edited = graphRow($one, ['name' => 'one', 'children' => [$edited]]);
    graphSave($form, $root, ['name' => 'edited', 'children' => [$edited]]);
    expect(GraphChild::count())->toBe(5)->and($four->fresh()->sold)->toBe(19)->and($four->fresh()->name)->toBe('last')->and($four->fresh()->position)->toBe(1)->and($sibling->fresh()->position)->toBe(0);
});

it('foreign final child leaves siblings unchanged before any mutation', function () {
    $root = GraphRoot::create();
    $one = $root->children()->create();
    $other = GraphRoot::create()->children()->create();
    $writes = [];
    GraphChild::saving(function ($model) use (&$writes) {
        $writes[] = $model->id;
    });
    $form = graphForm();
    $form->text('name');
    $form->repeater('children', 'Children', fn ($r) => $r->text('name'))->relation('children');
    expect(fn () => graphSave($form, $root, ['name' => 'bad', 'children' => [graphRow($one, ['name' => 'first']), graphRow($other, ['name' => 'foreign'])]]))->toThrow(ValidationException::class);
    expect($root->fresh()->name)->toBe('root')->and($one->fresh()->name)->toBe('child')->and($writes)->toBe([]);
    GraphChild::flushEventListeners();
});

it('duplicate keys ids and numeric aliases reject before mutation', function ($kind) {
    $root = GraphRoot::create();
    $one = $root->children()->create();
    $form = graphForm();
    $form->repeater('children', 'Children', fn ($r) => $r->text('name'))->relation('children');
    $a = graphRow($one, ['name' => 'bad']);
    $b = graphRow($one);
    if ($kind === 'id') {
        $b['_brume']['key'] = 'other';
    }
    if ($kind === 'alias') {
        $a['_brume']['id'] = '0'.$one->id;
        $b = ['_brume' => ['key' => 'new']];
    }
    expect(fn () => graphSave($form, $root, ['children' => [$a, $b]]))->toThrow(ValidationException::class);
    expect($one->fresh()->name)->toBe('child');
})->with(['key', 'id', 'alias']);

it('nested failure rolls back all rows', function () {
    $root = GraphRoot::create();
    $one = $root->children()->create();
    $two = $one->children()->create();
    $form = graphForm();
    graphTree($form, 2);
    GraphChild::saved(function ($row) use ($two) {
        if ($row->id === $two->id) {
            throw new RuntimeException('nested failure');
        }
    });
    expect(fn () => graphSave($form, $root, ['name' => 'changed', 'children' => [graphRow($one, ['name' => 'changed', 'children' => [graphRow($two, ['name' => 'changed'])]])]]))->toThrow(RuntimeException::class, 'nested failure');
    expect($root->fresh()->name)->toBe('root')->and($one->fresh()->name)->toBe('child')->and($two->fresh()->name)->toBe('child');
    GraphChild::flushEventListeners();
});

it('locks and refreshes root before editing callbacks validation and saving hook', function () {
    $stale = GraphRoot::create(['sold' => 1]);
    $stale->newQuery()->whereKey($stale->id)->update(['sold' => 12]);
    $form = graphForm();
    $form->number('sold')->readOnly();
    $form->text('name')->required()->showWhen(Conditions::eq(Conditions::root('sold'), 12));
    $form->editing(function ($record, $f) {
        if ($record->sold === 12) {
            $f->text('code')->required();
        }
    });
    expect(fn () => graphSave($form, $stale, ['sold' => 1]))->toThrow(ValidationException::class);
    $form->saving(function ($record, &$values) {
        expect($record->sold)->toBe(12);
        $record->sold = 13;
        $values['code'] = 'hook';
    });
    graphSave($form, $stale, ['name' => 'valid', 'code' => 'ok']);
    expect($stale->fresh()->sold)->toBe(13)->and($stale->fresh()->code)->toBe('hook')->and($stale->fresh()->name)->toBe('root');
});

it('rejects mixed connections before any write', function () {
    config(['database.connections.other' => config('database.connections.sqlite')]);
    $root = GraphRoot::create();
    $child = $root->children()->create();
    $form = graphForm();
    $form->text('name');
    $form->repeater('children', 'Children', function ($row) {
        $row->repeater('foreignChildren', 'Foreign', fn ($leaf) => $leaf->text('name'))->relation('foreignChildren');
    })->relation('children');
    expect(fn () => graphSave($form, $root, ['name' => 'bad', 'children' => [graphRow($child, ['foreignChildren' => []])]]))->toThrow(ValidationException::class);
    expect($root->fresh()->name)->toBe('root');
});

it('validates actual hook adjusted graph before any sibling write', function () {
    $root = GraphRoot::create();
    $child = $root->children()->create();
    $foreign = GraphRoot::create()->children()->create();
    $writes = [];
    $form = graphForm();
    $form->repeater('children', 'Children', fn ($row) => $row->text('name'))->relation('children');
    $form->saving(function ($record, &$values) use ($foreign) {
        $values['children'][] = graphRow($foreign, ['name' => 'hook foreign']);
    });
    GraphChild::saving(function ($row) use (&$writes) {
        $writes[] = $row->id;
    });
    expect(fn () => graphSave($form, $root, ['children' => [graphRow($child, ['name' => 'first'])]]))->toThrow(ValidationException::class);
    expect($writes)->toBe([])->and($child->fresh()->name)->toBe('child');
    GraphChild::flushEventListeners();
});

it('validates remote scalar and multiple choices with authoritative dependencies and preserves tags freedom', function () {
    $root = GraphRoot::create(['sold' => 1]);
    $allowed = GraphProduct::create();
    $foreign = GraphProduct::create(['tenant' => 2]);
    $source = OptionSource::make(fn ($context) => GraphProduct::where('tenant', $context->dependencies['tenant']))->label('name');
    $form = graphForm();
    $form->number('sold')->readOnly();
    $form->select('code')->remote($source, ['tenant' => Conditions::root('sold')]);
    $form->multipleSelect('codes')->remote($source, ['tenant' => Conditions::root('sold')]);
    expect(fn () => graphSave($form, $root, ['sold' => 2, 'code' => (string) $foreign->id]))->toThrow(ValidationException::class);
    expect(fn () => graphSave($form, $root, ['codes' => [(string) $allowed->id, (string) $foreign->id]]))->toThrow(ValidationException::class);
    graphSave($form, $root, ['code' => (string) $allowed->id, 'codes' => [(string) $allowed->id]]);
    expect($root->fresh()->code)->toBe((string) $allowed->id)->and($root->fresh()->codes)->toBe([(string) $allowed->id]);
    $free = graphForm();
    $free->tags('codes')->remote(graphSource());
    graphSave($free, $root, ['codes' => ['free text']]);
    expect($root->fresh()->codes)->toBe(['free text']);
});

it('rejects unchanged explicitly submitted ineligible selections but preserves omitted readonly and removed selections', function () {
    $root = GraphRoot::create();
    $product = GraphProduct::create(['root_id' => $root->id]);
    $product->update(['active' => false]);
    $form = graphForm();
    $form->text('name');
    $field = $form->relationPicker('products', 'Products', graphSource())->relation('products');
    expect(fn () => graphSave($form, $root, ['name' => 'bad', 'products' => [['id' => (string) $product->id]]]))->toThrow(ValidationException::class);
    expect($root->fresh()->name)->toBe('root');
    graphSave($form, $root, ['name' => 'omitted']);
    expect($product->fresh()->root_id)->toBe($root->id);
    $field->readOnly();
    graphSave($form, $root, ['products' => [['id' => '9999']]]);
    expect($product->fresh()->root_id)->toBe($root->id);
    $fresh = graphForm();
    $fresh->relationPicker('products', 'Products', graphSource())->relation('products');
    graphSave($fresh, $root, ['products' => []]);
    expect($product->fresh()->root_id)->toBeNull();
});

it('uses relation child authority before root numeric conditions and exact money comparison', function () {
    $root = GraphRoot::create();
    $child = $root->children()->create(['sold' => 500]);
    $form = graphForm();
    $form->text('name')->required()->showWhen(Conditions::eq(Conditions::root('children.0.sold'), '500.00'));
    $form->repeater('children', 'Children', function ($row) {
        $row->decimal('sold')->decimals(2)->readOnly();
        $row->text('name');
    })->relation('children');
    expect(fn () => graphSave($form, $root, ['children' => [graphRow($child, ['sold' => '0', 'name' => 'changed'])]]))->toThrow(ValidationException::class);
    graphSave($form, $root, ['name' => 'required', 'children' => [graphRow($child, ['sold' => '0', 'name' => 'changed'])]]);
    expect($root->fresh()->name)->toBe('required')->and($child->fresh()->sold)->toBe(500);
});

it('keeps JSON callback global nullable root below relation rows and strips HTTP unknowns', function ($create) {
    $root = $create ? null : GraphRoot::create(['sold' => 7]);
    $child = $root?->children()->create(['sold' => 21]);
    $seen = [];
    $form = graphForm();
    $form->repeater('children', 'Children', function ($row) use (&$seen) {
        $row->text('name');
        $row->repeater('data', 'JSON', function ($json) use (&$seen) {
            $json->text('name')->readOnly(function ($context) use (&$seen) {
                $seen[] = $context;

                return false;
            });
            $json->component('opaque', 'Opaque', 'Json');
        });
    })->relation('children');
    $row = ['_brume' => ['key' => 'new'], 'name' => 'child', 'data' => [['_brume' => ['key' => 'json'], 'name' => 'ok', 'opaque' => (object) [], 'forged' => true]]];
    if ($child) {
        $row['_brume'] = graphRow($child)['_brume'];
    }
    $request = Request::create('/', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['values' => ['children' => [$row]]]));
    $response = (new FormSaver)->save($form, $root, $request);
    $saved = GraphRoot::findOrFail($response['id'])->children()->first();
    $data = json_decode($saved->getRawOriginal('data'));
    expect($data[0]->opaque)->toBeInstanceOf(stdClass::class)->and(isset($data[0]->forged))->toBeFalse();
    expect(collect($seen)->every(fn ($context) => $create ? $context === null : $context instanceof GraphRoot && $context->sold === 7))->toBeTrue();
})->with([false, true]);

it('resolves root numeric source dependencies after relation reorder with locked row authority', function () {
    $root = GraphRoot::create();
    $one = $root->children()->create(['sold' => 1]);
    $two = $root->children()->create(['sold' => 2]);
    $allowed = GraphProduct::create(['tenant' => 2]);
    $wrong = GraphProduct::create(['tenant' => 1]);
    $form = graphForm();
    $form->select('code')->remote(OptionSource::make(fn ($c) => GraphProduct::where('tenant', $c->dependencies['tenant']))->label('name'), ['tenant' => Conditions::root('children.0.sold')]);
    $form->repeater('children', 'Children', fn ($row) => $row->number('sold')->readOnly())->relation('children');
    $rows = [graphRow($two, ['sold' => 1]), graphRow($one, ['sold' => 2])];
    expect(fn () => graphSave($form, $root, ['code' => (string) $wrong->id, 'children' => $rows]))->toThrow(ValidationException::class);
    graphSave($form, $root, ['code' => (string) $allowed->id, 'children' => $rows]);
    expect($root->fresh()->code)->toBe((string) $allowed->id);
});

it('rejects picker aliases before extra callbacks use new row authority', function () {
    $root = GraphRoot::create();
    $product = GraphProduct::create();
    $gift = $root->gifts()->create(['product_id' => $product->id, 'sold' => 6]);
    $contexts = [];
    $form = graphForm();
    $form->relationPicker('gifts', 'Gifts', graphSource())->relation('gifts')->associateBy('product_id')->extra(function ($row) use (&$contexts) {
        $row->number('quantity')->readOnly(function ($record) use (&$contexts) {
            $contexts[] = $record;

            return $record?->sold > 0;
        });
    });
    expect(fn () => graphSave($form, $root, ['gifts' => [['id' => '0'.$product->id, 'quantity' => 88]]]))->toThrow(ValidationException::class);
    expect($contexts)->toBe([])->and($gift->fresh()->quantity)->toBe(1);
});

it('rejects pivot association metadata declarations', function () {
    $root = GraphRoot::create();
    $form = graphForm();
    $field = $form->relationPicker('components', 'Components', graphSource())->relation('components')->extra(fn ($row) => $row->number('root_id'));
    expect(fn () => $field->relationship($root))->toThrow(InvalidArgumentException::class);
});

it('ignores inactive relation input including foreign identities', function () {
    $root = GraphRoot::create(['sold' => 0]);
    $child = $root->children()->create();
    $foreign = GraphRoot::create()->children()->create();
    $form = graphForm();
    $form->number('sold')->readOnly();
    $form->repeater('children', 'Children', fn ($row) => $row->text('name'))->relation('children')->enableWhen(Conditions::eq(Conditions::root('sold'), 1));
    graphSave($form, $root, ['children' => [graphRow($foreign, ['name' => 'forged'])]]);
    expect($child->fresh()->root_id)->toBe($root->id)->and($foreign->fresh()->name)->toBe('child');
});

it('writes belongsTo extras to scoped selection retaining undeclared attributes and correct callbacks', function () {
    $root = GraphRoot::create();
    $product = GraphProduct::create(['name' => 'old', 'tenant' => 1]);
    $seen = [];
    $form = graphForm();
    $form->relationPicker('owner', 'Owner', graphSource())->relation('owner')->multiple(false)->extra(function ($row) use (&$seen) {
        $row->text('name')->readOnly(function ($model) use (&$seen) {
            $seen[] = $model?->id;

            return false;
        });
    });
    graphSave($form, $root, ['owner' => ['id' => (string) $product->id, 'name' => 'new association']]);
    expect($product->fresh()->name)->toBe('new association')->and($product->fresh()->tenant)->toBe(1)->and(array_unique($seen))->toBe([null]);
    $seen = [];
    graphSave($form, $root, ['owner' => ['id' => (string) $product->id, 'name' => 'existing']]);
    expect($product->fresh()->name)->toBe('existing')->and(array_unique($seen))->toBe([$product->id]);
});

it('does not update belongsTo extras before a later invalid sibling', function () {
    $root = GraphRoot::create();
    $product = GraphProduct::create();
    $foreign = GraphRoot::create()->children()->create();
    $form = graphForm();
    $form->relationPicker('owner', 'Owner', graphSource())->relation('owner')->multiple(false)->extra(fn ($row) => $row->text('name'));
    $form->repeater('children', 'Children', fn ($row) => $row->text('name'))->relation('children');
    expect(fn () => graphSave($form, $root, ['owner' => ['id' => (string) $product->id, 'name' => 'bad'], 'children' => [graphRow($foreign)]]))->toThrow(ValidationException::class);
    expect($product->fresh()->name)->toBe('product')->and($root->fresh()->owner_id)->toBeNull();
});

it('rejects nested upload replacement until graph archival is installed', function () {
    $root = GraphRoot::create();
    $child = $root->children()->create(['name' => 'files/old.png']);
    $form = graphForm();
    $form->repeater('children', 'Children', fn ($row) => $row->file('name'))->relation('children');
    expect(fn () => graphSave($form, $root, ['children' => [graphRow($child, ['name' => ['token' => 'unarchived']])]]))->toThrow(ValidationException::class);
    expect($child->fresh()->name)->toBe('files/old.png');
    graphSave($form, $root, ['children' => [graphRow($child, ['name' => ['path' => 'files/old.png']])]]);
    expect($child->fresh()->name)->toBe('files/old.png');
});

it('keeps a frozen plan despite caller mutations and exposes prehook raw removal inventory', function () {
    $root = GraphRoot::create(['name' => 'old']);
    $child = $root->children()->create(['name' => 'files/old.png']);
    $form = graphForm();
    $form->text('name');
    $form->repeater('children', 'Children', fn ($row) => $row->file('name'))->relation('children');
    $root->getConnection()->transaction(function () use ($form, $root, $child) {
        $plan = (new RelationPlanner)->plan($form, $root, ['name' => 'planned', 'children' => []], $root);
        $operations = $plan->operations();
        $operations['model']->name = 'tampered';
        $operations['fields']['name']->readOnly();
        $original = $plan->inventory()['original'];
        expect(collect($original)->contains(fn ($field) => $field['owner']?->is($child) && $field['stored'] === 'files/old.png'))->toBeTrue();
        (new RelationSynchronizer)->save($plan);
        expect($root->fresh()->name)->toBe('planned')->and(GraphChild::find($child->id))->toBeNull();
    });
});

it('uses pivot quantities and null new pivot context for option dependencies', function () {
    $root = GraphRoot::create();
    $product = GraphProduct::create();
    $new = GraphProduct::create();
    $root->components()->attach($product, ['quantity' => 8]);
    $form = graphForm()->forRecord($root);
    $form->select('code')->remote(graphSource(), ['quantity' => Conditions::root('components.0.quantity')]);
    $form->relationPicker('components', 'Components', graphSource())->relation('components')->extra(fn ($row) => $row->number('quantity')->readOnly(fn ($record) => $record !== null));
    $registry = new DeclarationRegistry($form->fields(), $root);
    foreach ([[$product, 8], [$new, 999]] as [$selected, $expected]) {
        $resolver = new FormOptionContext($form, $registry, []);
        $context = $resolver->resolve($registry->entries['code'], [], (object) ['quantity' => 999], (object) ['quantity' => [(object) ['path' => 'components', 'key' => (string) $selected->id, 'id' => (string) $selected->id]]]);
        expect($context->dependencies['quantity'])->toBe($expected);
    }
});

it('does not run source query with unvalidated whole collection children', function () {
    $root = GraphRoot::create();
    $product = GraphProduct::create();
    $invoked = 0;
    $form = graphForm();
    $form->select('code')->remote(OptionSource::make(function ($context) use (&$invoked) {
        $invoked++;

        return GraphProduct::query();
    })->label('name'), ['data' => Conditions::root('data')]);
    $form->repeater('data', 'Data', fn ($row) => $row->component('opaque', 'Opaque', 'Json')->childRules(['needed' => ['required', 'string']]));
    expect(fn () => graphSave($form, $root, ['code' => (string) $product->id, 'data' => [['_brume' => ['key' => 'one'], 'opaque' => (object) ['needed' => []]]]]))->toThrow(ValidationException::class);
    expect($invoked)->toBe(0);
});

it('applies selection exclusions by source identity even when extra values differ', function () {
    $root = GraphRoot::create();
    $product = GraphProduct::create();
    $form = graphForm();
    $form->relationPicker('products', 'Products', graphSource())->relation('products')->extra(fn ($row) => $row->text('name'))->excludeSelectedIn([Conditions::root('components')]);
    $form->relationPicker('components', 'Components', graphSource())->relation('components')->extra(fn ($row) => $row->number('quantity'));
    expect(fn () => graphSave($form, $root, ['products' => [['id' => (string) $product->id, 'name' => 'other']], 'components' => [['id' => (string) $product->id, 'quantity' => 5]]]))->toThrow(ValidationException::class);
    expect($product->fresh()->root_id)->toBeNull()->and($root->components()->count())->toBe(0);
});

it('rejects relation identity aliases mapped through editable fields', function () {
    $root = GraphRoot::create();
    $child = $root->children()->create();
    $other = GraphRoot::create();
    $form = graphForm();
    $form->repeater('children', 'Children', fn ($row) => $row->number('owner')->attribute('root_id'))->relation('children');
    expect(fn () => graphSave($form, $root, ['children' => [graphRow($child, ['owner' => $other->id])]]))->toThrow(InvalidArgumentException::class);
    expect($child->fresh()->root_id)->toBe($root->id);
});

it('reinitializes stale cached declarations against protected counters', function () {
    $root = GraphRoot::create(['sold' => 0]);
    $form = graphForm()->forRecord($root);
    $form->editing(function ($record, $form) {
        if ($record->sold === 0) {
            $form->text('name');
        } else {
            $form->text('code')->required();
        }
    });
    $form->initialize();
    GraphRoot::whereKey($root->id)->update(['sold' => 9]);
    expect(fn () => graphSave($form, $root, ['name' => 'forged']))->toThrow(ValidationException::class);
    graphSave($form, $root, ['name' => 'forged', 'code' => 'valid']);
    expect($root->fresh()->name)->toBe('root')->and($root->fresh()->sold)->toBe(9)->and(array_keys($form->fields()))->toBe(['code']);
});

it('canonicalizes ancestor source identities before nested container callbacks', function () {
    $root = GraphRoot::create();
    $product = GraphProduct::create();
    $root->gifts()->create(['product_id' => $product->id]);
    $seen = [];
    $form = graphForm();
    $form->relationPicker('gifts', 'Gifts', graphSource())->relation('gifts')->associateBy('product_id')->extra(function ($row) use (&$seen) {
        $row->repeater('data', 'Data', fn ($json) => $json->text('name'))->readOnly(function ($context) use (&$seen) {
            $seen[] = $context;

            return false;
        });
    });
    expect(fn () => graphSave($form, $root, ['gifts' => [['id' => '0'.$product->id, 'data' => []]]]))->toThrow(ValidationException::class);
    expect($seen)->toBe([]);
});

it('validates new ancestor eligibility before invoking a nested dependent source', function () {
    $root = GraphRoot::create();
    $foreign = GraphProduct::create(['tenant' => 2]);
    $called = 0;
    $form = graphForm();
    $form->relationPicker('gifts', 'Gifts', graphSource())->relation('gifts')->associateBy('product_id')->extra(function ($row) use (&$called) {
        $row->select('quantity')->remote(OptionSource::make(function () use (&$called) {
            $called++;

            return GraphProduct::query();
        })->label('name'));
    });
    expect(fn () => graphSave($form, $root, ['gifts' => [['id' => (string) $foreign->id, 'quantity' => (string) $foreign->id]]]))->toThrow(ValidationException::class);
    expect($called)->toBe(0);
});

it('validates custom children of whole collection dependencies before ancestor source callbacks', function () {
    $root = GraphRoot::create();
    $product = GraphProduct::create();
    $called = 0;
    $form = graphForm();
    $form->relationPicker('gifts', 'Gifts', OptionSource::make(function () use (&$called) {
        $called++;

        return GraphProduct::query();
    })->label('name'))->relation('gifts')->associateBy('product_id')->remote(OptionSource::make(function () use (&$called) {
        $called++;

        return GraphProduct::query();
    })->label('name'), ['data' => Conditions::root('data')])->extra(fn ($row) => $row->number('quantity'));
    $form->repeater('data', 'Data', fn ($row) => $row->component('opaque', 'Opaque', 'Json')->childRules(['needed' => ['required', 'string']]));
    expect(fn () => graphSave($form, $root, ['gifts' => [['id' => (string) $product->id, 'quantity' => 1]], 'data' => [['_brume' => ['key' => 'x'], 'opaque' => (object) ['needed' => []]]]]))->toThrow(ValidationException::class);
    expect($called)->toBe(0);
});

it('ignores inactive picker aliases and duplicate row metadata', function () {
    $root = GraphRoot::create(['sold' => 0]);
    $product = GraphProduct::create();
    $form = graphForm();
    $form->number('sold')->readOnly();
    $form->relationPicker('gifts', 'Gifts', graphSource())->relation('gifts')->associateBy('product_id')->enableWhen(Conditions::eq(Conditions::root('sold'), 1))->extra(fn ($row) => $row->number('quantity'));
    graphSave($form, $root, ['gifts' => [['id' => '0'.$product->id, 'quantity' => 1], ['id' => '0'.$product->id, 'quantity' => 2]]]);
    expect($root->gifts()->count())->toBe(0);
});

it('rejects model relations under JSON storage owners at declaration resolution', function () {
    $root = GraphRoot::create();
    $child = $root->children()->create();
    $form = graphForm();
    $form->repeater('data', 'JSON', fn ($row) => $row->repeater('children', 'Children', fn ($child) => $child->text('name'))->relation('children'));
    expect(fn () => graphSave($form, $root, ['data' => [['_brume' => ['key' => 'json'], 'children' => [graphRow($child, ['name' => 'bad'])]]]]))->toThrow(InvalidArgumentException::class);
    expect($child->fresh()->name)->toBe('child');
});

it('verifies zero extra whole picker dependencies before querying dependents', function () {
    $root = GraphRoot::create();
    $foreign = GraphProduct::create(['tenant' => 2]);
    $called = 0;
    $form = graphForm();
    $form->relationPicker('components', 'Components', graphSource())->relation('components');
    $form->relationPicker('gifts', 'Gifts', OptionSource::make(function () use (&$called) {
        $called++;

        return GraphProduct::query();
    })->label('name'))->relation('gifts')->associateBy('product_id')->remote(OptionSource::make(function () use (&$called) {
        $called++;

        return GraphProduct::query();
    })->label('name'), ['components' => Conditions::root('components')])->extra(fn ($row) => $row->number('quantity'));
    expect(fn () => graphSave($form, $root, ['components' => [['id' => (string) $foreign->id]], 'gifts' => [['id' => (string) $foreign->id, 'quantity' => 2]]]))->toThrow(ValidationException::class);
    expect($called)->toBe(0);
});

it('preserves exact string selection identities including numeric looking and case sensitive keys', function () {
    foreach (['02', '2', 'abc'] as $id) {
        CodeItem::create(['code' => $id, 'name' => $id]);
    }
    $root = GraphRoot::create();
    $form = graphForm();
    $source = OptionSource::make(CodeItem::class)->label('name');
    $form->select('code')->remote($source);
    $form->multipleSelect('codes')->remote($source);
    graphSave($form, $root, ['code' => '02', 'codes' => ['02', '2']]);
    expect($root->fresh()->code)->toBe('02')->and($root->fresh()->codes)->toBe(['02', '2']);
    expect(fn () => graphSave($form, $root, ['code' => 'ABC']))->toThrow(ValidationException::class);
    expect($root->fresh()->code)->toBe('02');
});

it('rejects actual recursive source projection cycles before graph writes', function () {
    $root = GraphRoot::create();
    $product = GraphProduct::create();
    $form = graphForm();
    $form->relationPicker('components', 'Components', graphSource())->relation('components')->remote(graphSource(), ['gifts' => Conditions::root('gifts')]);
    $form->relationPicker('gifts', 'Gifts', graphSource())->relation('gifts')->associateBy('product_id')->remote(graphSource(), ['components' => Conditions::root('components')]);
    expect(fn () => graphSave($form, $root, ['components' => [['id' => (string) $product->id]], 'gifts' => [['id' => (string) $product->id]]]))->toThrow(ValidationException::class);
    expect($root->components()->count())->toBe(0)->and($root->gifts()->count())->toBe(0);
});

it('allows shared acyclic whole collection projections with readonly values and semantic custom objects', function () {
    $root = GraphRoot::create(['data' => [['_brume' => ['key' => 'json'], 'amount' => '500.00', 'opaque' => (object) [], 'undeclared' => 'not projected']]]);
    $product = GraphProduct::create();
    $contexts = [];
    $source = OptionSource::make(function ($context) use (&$contexts) {
        $contexts[] = $context->dependencies['data'];

        return GraphProduct::query();
    })->label('name');
    $form = graphForm();
    $form->select('code')->remote($source, ['data' => Conditions::root('data')]);
    $form->multipleSelect('codes')->remote($source, ['data' => Conditions::root('data')]);
    $form->repeater('data', 'Data', function ($row) {
        $row->decimal('amount')->decimals(2)->readOnly();
        $row->component('opaque', 'Opaque', 'Json');
    });
    graphSave($form, $root, ['code' => (string) $product->id, 'codes' => [(string) $product->id], 'data' => [['_brume' => ['key' => 'json'], 'amount' => '0', 'opaque' => (object) [], 'forged' => true]]]);
    foreach ($contexts as $data) {
        expect($data[0]['amount'])->toBe('500.00')->and($data[0]['opaque'])->toBeInstanceOf(stdClass::class)->and(array_keys($data[0]))->toBe(['_brume', 'amount', 'opaque']);
    }
});

it('keeps explicit remote choice validation rules in addition to source eligibility', function () {
    $root = GraphRoot::create();
    $one = GraphProduct::create();
    $two = GraphProduct::create();
    $form = graphForm();
    $form->select('code')->remote(graphSource())->rules([Rule::in([(string) $one->id])]);
    expect(fn () => graphSave($form, $root, ['code' => (string) $two->id]))->toThrow(ValidationException::class);
    expect($root->fresh()->code)->toBeNull();
});
