<?php

use Brume\Form\ConditionPath;
use Brume\Form\Conditions;
use Brume\Form\Form;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Models\User;
use Brume\Options\OptionContext;
use Brume\Options\OptionSource;
use Brume\Tests\Support\JsonSchema;
use Brume\Tests\Support\Models\CellItem;
use Brume\Tests\Support\Models\CodeItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

it('source search and hydration preserve scope and clone queries', function () {
    $allowed = brumeUser(['name' => 'Allowed']);
    $foreign = brumeUser(['username' => 'foreign', 'name' => 'Secret']);
    $query = User::whereKey($allowed->id);
    $source = OptionSource::make(fn (OptionContext $context) => $query)->label('name');
    $context = new OptionContext(null);
    expect($source->response($context, ['q' => 'Secret'])['data'])->toBe([])
        ->and($source->response($context, ['value' => [(string) $foreign->id, (string) $allowed->id]])['data'])
        ->toBe([['value' => (string) $allowed->id, 'label' => 'Allowed']])
        ->and($source->query($context)->count())->toBe(1)
        ->and($query->count())->toBe(1);
});

it('picker sections and projections use the scoped source', function () {
    $allowed = brumeUser(['name' => 'Allowed']);
    $other = brumeUser(['username' => 'other', 'name' => 'Other']);
    $source = OptionSource::make(User::class)->label('name')->columns(['username' => 'Account'])
        ->sections(['allowed' => 'Allowed', 'other' => 'Other'], function (Builder $query, string $section) use ($allowed, $other) {
            $query->whereKey($section === 'allowed' ? $allowed->id : $other->id);
        });
    $result = $source->response(new OptionContext(null), [], true);
    expect($result['data'])->toBe([['value' => (string) $allowed->id, 'label' => 'Allowed', 'columns' => ['username' => 'admin']]])
        ->and($result['columns'])->toBe([['key' => 'username', 'label' => 'Account']])
        ->and(JsonSchema::errors($result, 'PickerSearchResponse'))->toBe([])
        ->and($source->response(new OptionContext(null), ['section' => 'other', 'value' => [(string) $allowed->id]], true)['data'])->toBe([]);
});

it('new sources reject malformed or excessive request parameters', function (array $parameters) {
    OptionSource::make(User::class)->label('name')->response(new OptionContext(null), $parameters);
})->with([
    'zero page' => [['page' => '0']], 'array page' => [['page' => ['1']]],
    'huge page' => [['page' => '9999999999999999999']], 'oversized page' => [['per_page' => '51']],
    'array query' => [['q' => ['x']]], 'bad id' => [['value' => ['1abc']]],
    'duplicate id' => [['value' => ['1', '01']]], 'too many ids' => [['value' => range(1, 101)]],
    'associative ids' => [['value' => ['x' => '1']]], 'unknown section' => [['section' => 'nope']],
])->throws(ValidationException::class);

it('picker projections and search refuse hidden attributes', function (string $configuration) {
    $source = OptionSource::make(User::class)->label('name');
    match ($configuration) {
        'label' => $source->label('password'), 'search' => $source->search(['password']),
        'columns' => $source->columns(['password' => 'Secret']), 'depth' => $source->depth('password'),
    };
    $source->response(new OptionContext(null), [], true);
})->with(['label', 'search', 'columns', 'depth'])->throws(InvalidArgumentException::class);

class OptionsRecord extends CellItem
{
    protected $table = 'cell_items';

    public function sourceOwner()
    {
        return $this->belongsTo(User::class, 'customer');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'shop_id');
    }
}

class OptionsResource extends Brume\Resource\Resource
{
    protected string $model = OptionsRecord::class;

    public static ?Closure $declare = null;

    protected function query(): Builder
    {
        return OptionsRecord::where('status', 'root');
    }

    protected function grid(Grid $grid): void
    {
        $grid->column('customer');
    }

    protected function form(Form $form): void
    {
        (self::$declare)($form);
    }
}

function formOptionsRoutes(Closure $declare): void
{
    OptionsResource::$declare = $declare;
    test()->actingAs(brumeUser(), 'brume');
    Route::middleware(HandleBrumeRequests::class)
        ->group(fn () => Route::brumeResource('option-records', OptionsResource::class));
    Route::getRoutes()->refreshNameLookups();
}

function formOptionsRequest(int|string|null $id, string $field, array $params = [])
{
    return test()->getJson('/option-records/'.($id === null ? '' : $id.'/').'_brume/form-options?'.http_build_query(['field' => $field] + $params));
}

it('generated form options initialize hooks and use scoped readonly root dependencies', function () {
    $root = OptionsRecord::create(['shop_id' => 1, 'customer' => 'Allowed', 'status' => 'root']);
    $foreign = OptionsRecord::create(['shop_id' => 1, 'customer' => 'Secret', 'status' => 'foreign']);
    formOptionsRoutes(function ($form) {
        $form->text('customer')->readOnly();
        $form->editing(function ($record, $form) {
            $form->select('choice')->remote(OptionSource::make(fn (OptionContext $c) => OptionsRecord::where('customer', $c->dependencies['name']))->label('customer'), ['name' => ConditionPath::root('customer')]);
        });
    });
    $page = $this->get('/option-records/'.$root->id.'/edit', ['X-Brume-Form' => '1'])->assertOk();
    expect($page->json('page.form.nodes.1.field.remote.url'))->toBe('/option-records/'.$root->id.'/_brume/form-options?field=choice');
    $response = formOptionsRequest($root->id, 'choice', ['depends' => json_encode(['name' => 'Secret'])])->assertOk();
    expect($response->json('data'))->toBe([['value' => (string) $root->id, 'label' => 'Allowed']]);
    formOptionsRequest($foreign->id, 'choice')->assertNotFound();
    formOptionsRequest($root->id, 'unknown')->assertNotFound();
    formOptionsRequest($root->id, 'choice', ['depends' => '[]'])->assertUnprocessable();
});

it('resource modifiers cover both generated form options routes', function () {
    $registrar = Route::brumeResource('scoped-options', OptionsResource::class)->names('scoped-options')->middleware('can:options')->whereNumber('_brume_record');
    $routes = collect($registrar->routes())->filter(fn ($route) => str_contains($route->uri(), 'form-options'));
    expect($routes)->toHaveCount(2);
    foreach ($routes as $route) {
        expect($route->middleware())->toContain('can:options')->and($route->wheres)->toHaveKey('_brume_record');
    }
});

it('readonly cross-row dependencies use verified target context after reorder', function () {
    $root = OptionsRecord::create(['shop_id' => 1, 'customer' => 'Root', 'status' => 'root', 'tags' => [
        ['_brume' => ['key' => 'a'], 'name' => 'Alpha'], ['_brume' => ['key' => 'b'], 'name' => 'Beta'],
    ]]);
    $alpha = brumeUser(['username' => 'alpha', 'name' => 'Alpha']);
    formOptionsRoutes(function ($form) {
        $form->repeater('tags', 'Rows', function ($row) {
            $row->text('name')->readOnly();
            $row->select('choice')->remote(OptionSource::make(fn (OptionContext $c) => User::where('name', $c->dependencies['name']))->label('name'), ['name' => ConditionPath::root('tags.0.name')]);
        });
    });
    $params = ['depends' => '{"name":"Beta"}', 'rowContext' => '[{"path":"tags","key":"b"}]', 'dependencyContexts' => '{"name":[{"path":"tags","key":"a"}]}'];
    expect(formOptionsRequest($root->id, 'tags.*.choice', $params)->assertOk()->json('data'))->toBe([['value' => (string) $alpha->id, 'label' => 'Alpha']]);
    unset($params['dependencyContexts']);
    formOptionsRequest($root->id, 'tags.*.choice', $params)->assertUnprocessable();
    $params['dependencyContexts'] = '{"unknown":[]}';
    formOptionsRequest($root->id, 'tags.*.choice', $params)->assertUnprocessable();
    $params['dependencyContexts'] = '{"name":[{"path":"other","key":"a"}]}';
    formOptionsRequest($root->id, 'tags.*.choice', $params)->assertUnprocessable();
});

it('explicit GET depends preserves opaque custom object and list shapes', function () {
    $root = OptionsRecord::create(['shop_id' => 1, 'customer' => 'Root', 'status' => 'root']);
    formOptionsRoutes(function ($form) {
        $form->component('custom', 'Custom', 'Custom');
        $form->select('choice')->remote(OptionSource::make(function (OptionContext $context) {
            $custom = $context->dependencies['custom'];

            return User::where('name', $custom instanceof stdClass && $custom->nested instanceof stdClass ? 'object' : 'list');
        })->label('name'), ['custom' => ConditionPath::root('custom')]);
    });
    $object = brumeUser(['username' => 'object', 'name' => 'object']);
    $list = brumeUser(['username' => 'list', 'name' => 'list']);
    expect(formOptionsRequest($root->id, 'choice', ['depends' => '{"custom":{"nested":{}}}'])->assertOk()->json('data.0.value'))->toBe((string) $object->id)
        ->and(formOptionsRequest($root->id, 'choice', ['depends' => '{"custom":[]}'])->assertOk()->json('data.0.value'))->toBe((string) $list->id);
});

it('resolves nested relation targets through each actual parent and refuses foreign child ids', function () {
    $root = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root']);
    $child = $root->children()->create(['customer' => 'Child', 'status' => 'locked']);
    $grandchild = $child->children()->create(['customer' => 'Alpha', 'status' => 'locked']);
    $foreign = OptionsRecord::create(['shop_id' => 99999, 'customer' => 'Secret', 'status' => 'locked']);
    formOptionsRoutes(function ($form) {
        $form->repeater('children', 'Children', function ($row) {
            $row->repeater('children', 'Children', function ($row) {
                $row->text('customer')->readOnly(fn ($record) => $record?->status === 'locked');
                $row->select('choice')->remote(OptionSource::make(fn (OptionContext $c) => OptionsRecord::where('customer', $c->dependencies['name']))->label('customer'), ['name' => ConditionPath::row('customer')]);
            })->relation('children');
        })->relation('children');
    });
    $chain = [['path' => 'children', 'key' => 'client-key', 'id' => (string) $child->id], ['path' => 'children.*.children', 'key' => 'other-key', 'id' => (string) $grandchild->id]];
    $params = ['depends' => '{"name":"Secret"}', 'rowContext' => json_encode($chain)];
    expect(formOptionsRequest($root->id, 'children.*.children.*.choice', $params)->assertOk()->json('data.0.value'))->toBe((string) $grandchild->id);
    $chain[1]['id'] = (string) $foreign->id;
    $params['rowContext'] = json_encode($chain);
    formOptionsRequest($root->id, 'children.*.children.*.choice', $params)->assertUnprocessable();
});

it('preserves canonical legacy JSON identity under reordered metadata keys at deeper levels', function () {
    $root = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root', 'tags' => [['name' => 'Outer', 'rows' => [['name' => 'Alpha']]]]]);
    formOptionsRoutes(function ($form) {
        $form->repeater('tags', 'Rows', function ($row) {
            $row->text('name');
            $row->repeater('rows', 'Rows', function ($row) {
                $row->text('name')->readOnly();
                $row->select('choice')->remote(OptionSource::make(fn (OptionContext $c) => User::where('name', $c->dependencies['name']))->label('name'), ['name' => ConditionPath::row('name')]);
            });
        });
    });
    $alpha = brumeUser(['username' => 'alpha', 'name' => 'Alpha']);
    $page = $this->get('/option-records/'.$root->id.'/edit', ['X-Brume-Form' => '1'])->assertOk();
    $chain = [
        ['key' => $page->json('page.values.tags.0._brume.key'), 'path' => 'tags'],
        ['key' => $page->json('page.values.tags.0.rows.0._brume.key'), 'path' => 'tags.*.rows'],
    ];
    $params = ['depends' => '{"name":"forged"}', 'rowContext' => json_encode($chain)];
    expect(formOptionsRequest($root->id, 'tags.*.rows.*.choice', $params)->assertOk()->json('data.0.value'))->toBe((string) $alpha->id);
});

it('checks new picker owner source eligibility before invoking child options and preserves intermediary identity', function () {
    $root = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root']);
    $allowed = brumeUser(['username' => 'allowed', 'name' => 'Allowed']);
    $foreign = brumeUser(['username' => 'foreign', 'name' => 'Foreign']);
    $intermediary = $root->children()->create(['customer' => (string) $allowed->id, 'note' => 'Owned', 'status' => 'locked']);
    $seen = [];
    $called = 0;
    formOptionsRoutes(function ($form) use (&$seen, &$called) {
        $form->text('customer')->readOnly();
        $form->relationPicker('products', 'Products', OptionSource::make(fn (OptionContext $c) => User::where('name', $c->dependencies['owner'] === 'Root' ? 'Allowed' : 'Foreign'))->label('name'))
            ->remote(OptionSource::make(fn (OptionContext $c) => User::where('name', $c->dependencies['owner'] === 'Root' ? 'Allowed' : 'Foreign'))->label('name'), ['owner' => ConditionPath::root('customer')])
            ->relation('children')->associateBy('customer')->extra(function ($row) use (&$seen, &$called) {
                $row->text('note')->default('New')->readOnly(function ($record) use (&$seen) {
                    $seen[] = $record?->getKey();

                    return true;
                });
                $row->select('choice')->remote(OptionSource::make(function (OptionContext $c) use (&$called) {
                    $called++;

                    return User::where('name', $c->dependencies['note'] === 'Owned' ? 'Allowed' : 'No match');
                })->label('name'), ['note' => ConditionPath::row('note')]);
            });
    });
    $params = ['depends' => '{"note":"forged"}', 'rowContext' => json_encode([['path' => 'products', 'key' => (string) $allowed->id, 'id' => (string) $allowed->id]]), 'ancestorSources' => '[{"path":"products","rowContext":[],"depends":{"owner":"forged"}}]'];
    expect(formOptionsRequest($root->id, 'products.*.choice', $params)->assertOk()->json('data.0.value'))->toBe((string) $allowed->id);
    expect($seen)->toContain($intermediary->id);
    $params['rowContext'] = json_encode([['path' => 'products', 'key' => (string) $foreign->id, 'id' => (string) $foreign->id]]);
    formOptionsRequest($root->id, 'products.*.choice', $params)->assertUnprocessable();
    expect($called)->toBe(1);
    $intermediary->delete();
    $params['rowContext'] = json_encode([['path' => 'products', 'key' => (string) $allowed->id, 'id' => (string) $allowed->id]]);
    formOptionsRequest($root->id, 'products.*.choice', $params)->assertOk();
    expect($seen)->toContain(null)->and($called)->toBe(2);
});

it('whole JSON collection dependencies validate editable children and restore nested readonly authority', function () {
    $root = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root', 'tags' => [['_brume' => ['key' => 'a'], 'rows' => [['_brume' => ['key' => 'b'], 'amount' => '1.20', 'name' => 'Allowed']]]]]);
    formOptionsRoutes(function ($form) {
        $form->repeater('tags', 'Rows', function ($row) {
            $row->repeater('rows', 'Inner', function ($row) {
                $row->currency('amount')->decimals(2);
                $row->text('name')->readOnly();
            });
        });
        $form->select('choice')->remote(OptionSource::make(fn (OptionContext $c) => User::where('name', data_get($c->dependencies, 'rows.0.rows.0.name')))->label('name'), ['rows' => ConditionPath::root('tags')]);
    });
    $allowed = brumeUser(['username' => 'allowed', 'name' => 'Allowed']);
    $depends = ['rows' => [['_brume' => ['key' => 'a'], 'rows' => [['_brume' => ['key' => 'b'], 'amount' => '2', 'name' => 'Foreign']]]]];
    expect(formOptionsRequest($root->id, 'choice', ['depends' => json_encode($depends)])->assertOk()->json('data.0.value'))->toBe((string) $allowed->id);
    $depends['rows'][0]['rows'][0]['amount'] = 'bad';
    formOptionsRequest($root->id, 'choice', ['depends' => json_encode($depends)])->assertUnprocessable();
});

it('whole picker dependencies with no extras verify source eligibility before the dependent query', function () {
    $root = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root']);
    $allowed = brumeUser(['username' => 'allowed', 'name' => 'Allowed']);
    $foreign = brumeUser(['username' => 'foreign', 'name' => 'Foreign']);
    $called = 0;
    formOptionsRoutes(function ($form) use (&$called) {
        $form->relationPicker('products', 'Products', OptionSource::make(fn () => User::where('name', 'Allowed'))->label('name'))->relation('children')->associateBy('customer');
        $form->select('choice')->remote(OptionSource::make(function (OptionContext $c) use (&$called) {
            $called++;

            return User::whereKey(data_get($c->dependencies, 'products.0.id'));
        })->label('name'), ['products' => ConditionPath::root('products')]);
    });
    $params = ['depends' => json_encode(['products' => [['id' => (string) $foreign->id]]]), 'ancestorSources' => '[{"path":"products","rowContext":[],"depends":{}}]'];
    formOptionsRequest($root->id, 'choice', $params)->assertUnprocessable();
    expect($called)->toBe(0);
    formOptionsRequest($root->id, 'choice', ['depends' => $params['depends']])->assertUnprocessable();
    $params['depends'] = json_encode(['products' => [['id' => (string) $allowed->id]]]);
    expect(formOptionsRequest($root->id, 'choice', $params)->assertOk()->json('data.0.value'))->toBe((string) $allowed->id);
});

it('distinguishes repeated ancestor source instances and rejects duplicate unreachable or cyclic metadata', function () {
    $root = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root']);
    $a = $root->children()->create(['customer' => 'A']);
    $b = $root->children()->create(['customer' => 'B']);
    $pa = brumeUser(['username' => 'a', 'name' => 'A']);
    $pb = brumeUser(['username' => 'b', 'name' => 'B']);
    formOptionsRoutes(function ($form) {
        $form->repeater('children', 'Children', function ($row) {
            $row->text('customer')->readOnly();
            $source = OptionSource::make(fn (OptionContext $c) => User::where('name', $c->dependencies['category']))->label('name');
            $row->relationPicker('products', 'Products', $source)->remote($source, ['category' => ConditionPath::row('customer')])->relation('children')->associateBy('customer')->extra(function ($row) {
                $row->text('note');
                $row->select('choice')->remote(OptionSource::make(User::class)->label('name'), ['note' => ConditionPath::root('children.1.products.0.note')]);
            });
        })->relation('children');
    });
    $outerA = ['path' => 'children', 'key' => 'a', 'id' => (string) $a->id];
    $outerB = ['path' => 'children', 'key' => 'b', 'id' => (string) $b->id];
    $selectedA = ['path' => 'children.*.products', 'key' => (string) $pa->id, 'id' => (string) $pa->id];
    $selectedB = ['path' => 'children.*.products', 'key' => (string) $pb->id, 'id' => (string) $pb->id];
    $ancestors = [
        ['path' => 'children.*.products', 'rowContext' => [$outerA], 'depends' => ['category' => 'forged']],
        ['path' => 'children.*.products', 'rowContext' => [$outerB], 'depends' => ['category' => 'forged']],
    ];
    $params = ['depends' => '{"note":"ok"}', 'rowContext' => json_encode([$outerA, $selectedA]), 'dependencyContexts' => json_encode(['note' => [$outerB, $selectedB]]), 'ancestorSources' => json_encode($ancestors)];
    formOptionsRequest($root->id, 'children.*.products.*.choice', $params)->assertOk();
    $params['dependencyContexts'] = json_encode(['note' => [$outerB, $selectedA]]);
    formOptionsRequest($root->id, 'children.*.products.*.choice', $params)->assertUnprocessable();
    $params['dependencyContexts'] = json_encode(['note' => [$outerA, $selectedA]]);
    formOptionsRequest($root->id, 'children.*.products.*.choice', $params)->assertUnprocessable(); // B metadata is now unreachable.
    $params['ancestorSources'] = json_encode([$ancestors[0]]);
    formOptionsRequest($root->id, 'children.*.products.*.choice', $params)->assertOk(); // Shared instance is reused.
    $params['ancestorSources'] = json_encode([$ancestors[0], $ancestors[0]]);
    formOptionsRequest($root->id, 'children.*.products.*.choice', $params)->assertUnprocessable();
});

it('rejects a real source authority cycle before invoking the child query', function () {
    $root = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root']);
    formOptionsRoutes(function ($form) {
        $source = OptionSource::make(User::class)->label('name');
        $form->relationPicker('products', 'Products', $source)->remote($source, ['note' => ConditionPath::root('products.0.note')])->relation('children')->associateBy('customer')->extra(function ($row) {
            $row->text('note');
            $row->select('choice')->remote(OptionSource::make(User::class)->label('name'));
        });
    });
    $chain = [['path' => 'products', 'key' => '1', 'id' => '1']];
    formOptionsRequest($root->id, 'products.*.choice', ['rowContext' => json_encode($chain), 'ancestorSources' => json_encode([['path' => 'products', 'rowContext' => [], 'depends' => ['note' => 'ok'], 'dependencyContexts' => ['note' => $chain]]])])->assertUnprocessable();
});

it('derives legacy JSON identity from the scoped related model rather than a forged outer UI key', function () {
    $root = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root']);
    $child = $root->children()->create(['customer' => 'Child', 'tags' => [['name' => 'Allowed']]]);
    formOptionsRoutes(function ($form) {
        $form->repeater('children', 'Children', function ($row) {
            $row->repeater('tags', 'Tags', function ($row) {
                $row->text('name')->readOnly();
                $row->select('choice')->remote(OptionSource::make(fn (OptionContext $c) => User::where('name', $c->dependencies['name']))->label('name'), ['name' => ConditionPath::row('name')]);
            });
        })->relation('children');
    });
    $allowed = brumeUser(['username' => 'allowed', 'name' => 'Allowed']);
    $page = $this->get('/option-records/'.$root->id.'/edit', ['X-Brume-Form' => '1'])->assertOk();
    $chain = [['path' => 'children', 'key' => 'forged-ui-key', 'id' => (string) $child->id], ['path' => 'children.*.tags', 'key' => $page->json('page.values.children.0.tags.0._brume.key')]];
    expect(formOptionsRequest($root->id, 'children.*.tags.*.choice', ['rowContext' => json_encode($chain), 'depends' => '{"name":"foreign"}'])->assertOk()->json('data.0.value'))->toBe((string) $allowed->id);
});

it('refuses a forged sibling target chain for a row-relative dependency', function () {
    $root = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root', 'tags' => [['_brume' => ['key' => 'a'], 'name' => 'A'], ['_brume' => ['key' => 'b'], 'name' => 'B']]]);
    formOptionsRoutes(function ($form) {
        $form->repeater('tags', 'Tags', function ($row) {
            $row->text('name')->readOnly();
            $row->select('choice')->remote(OptionSource::make(fn (OptionContext $c) => User::where('name', $c->dependencies['name']))->label('name'), ['name' => ConditionPath::row('name')]);
        });
    });
    formOptionsRequest($root->id, 'tags.*.choice', ['depends' => '{"name":"B"}', 'rowContext' => '[{"path":"tags","key":"a"}]', 'dependencyContexts' => '{"name":[{"path":"tags","key":"b"}]}'])->assertUnprocessable();
});

it('rejects numeric picker aliases before readonly authority resolution for owner target and collection contexts', function (string $mode) {
    $root = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root']);
    $product = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Product', 'status' => 'source']);
    $owned = $root->children()->create(['customer' => (string) $product->id, 'note' => 'Owned']);
    $candidate = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Owned', 'status' => 'candidate']);
    OptionsRecord::create(['shop_id' => 999, 'customer' => 'New', 'status' => 'candidate']);
    $seen = [];
    formOptionsRoutes(function ($form) use (&$seen) {
        $childSource = OptionSource::make(function (OptionContext $c) use (&$seen) {
            $name = $c->dependencies['name'] ?? data_get($c->dependencies, 'products.0.note');
            $seen[] = $name;

            return OptionsRecord::where('status', 'candidate')->where('customer', $name);
        })->label('customer');
        $form->relationPicker('products', 'Products', OptionSource::make(fn () => OptionsRecord::where('status', 'source'))->label('customer'))->relation('children')->associateBy('customer')->extra(function ($row) use ($childSource) {
            $row->text('note')->default('New')->readOnly();
            $row->select('choice')->remote($childSource, ['name' => ConditionPath::row('note')]);
        });
        $form->select('target')->remote($childSource, ['name' => ConditionPath::root('products.0.note')]);
        $form->select('collection')->remote($childSource, ['products' => ConditionPath::root('products')]);
    });
    $request = function (string $id) use ($mode, $root) {
        $chain = [['path' => 'products', 'key' => $id, 'id' => $id]];
        [$field, $parameters] = match ($mode) {
            'owner' => ['products.*.choice', ['rowContext' => json_encode($chain), 'depends' => '{"name":"forged"}']],
            'target' => ['target', ['dependencyContexts' => json_encode(['name' => $chain]), 'depends' => '{"name":"forged"}']],
            default => ['collection', ['depends' => json_encode(['products' => [['id' => $id, 'note' => 'forged']]])]],
        };
        $parameters['ancestorSources'] = '[{"path":"products","rowContext":[],"depends":{}}]';

        return formOptionsRequest($root->id, $field, $parameters);
    };
    expect($request((string) $product->id)->assertOk()->json('data.0.value'))->toBe((string) $candidate->id);
    $request('0'.$product->id)->assertUnprocessable();
    expect($seen)->toBe(['Owned']);
    $product->update(['status' => 'inactive']);
    expect($request((string) $product->id)->assertOk()->json('data.0.value'))->toBe((string) $candidate->id);
    $owned->delete();
    $request((string) $product->id)->assertUnprocessable();
    $product->update(['status' => 'source']);
    $request((string) $product->id)->assertOk();
    expect($seen)->toBe(['Owned', 'Owned', 'New']);
    formOptionsRequest($root->id, 'collection', ['depends' => json_encode(['products' => [['id' => (string) $product->id], ['id' => '0'.$product->id]]])])->assertUnprocessable();
    formOptionsRequest($root->id, 'collection', ['depends' => json_encode(['products' => [['id' => (string) $product->id], ['id' => (string) $product->id]]])])->assertUnprocessable();
})->with(['owner', 'target', 'collection']);

it('retains exact string picker source identities including leading zero and case differences', function () {
    $root = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root']);
    $root->children()->create(['customer' => '02', 'note' => 'Owned']);
    CodeItem::create(['code' => '02', 'name' => 'Leading']);
    CodeItem::create(['code' => 'Abc', 'name' => 'Mixed']);
    $seen = [];
    formOptionsRoutes(function ($form) use (&$seen) {
        $form->relationPicker('products', 'Products', OptionSource::make(CodeItem::class)->label('name'))->relation('children')->associateBy('customer')->extra(function ($row) use (&$seen) {
            $row->text('note')->readOnly()->default('New');
            $row->select('choice')->remote(OptionSource::make(function (OptionContext $c) use (&$seen) {
                $seen[] = $c->dependencies['name'];

                return User::query();
            })->label('name'), ['name' => ConditionPath::row('note')]);
        });
    });
    foreach (['02' => 200, '2' => 422, 'Abc' => 200, 'abc' => 422] as $id => $status) {
        formOptionsRequest($root->id, 'products.*.choice', ['rowContext' => json_encode([['path' => 'products', 'key' => (string) $id, 'id' => (string) $id]])])->assertStatus($status);
    }
    expect($seen)->toBe(['Owned', 'New']);
});

it('uses one canonical picker identity policy for nested ancestor instances', function () {
    $root = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root']);
    $first = OptionsRecord::create(['shop_id' => 999, 'customer' => 'First', 'status' => 'source']);
    $second = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Second', 'status' => 'source']);
    $intermediary = $root->children()->create(['customer' => (string) $first->id]);
    $intermediary->children()->create(['customer' => (string) $second->id, 'note' => 'Nested']);
    $called = 0;
    formOptionsRoutes(function ($form) use (&$called) {
        $source = OptionSource::make(fn () => OptionsRecord::where('status', 'source'))->label('customer');
        $form->relationPicker('products', 'Products', $source)->relation('children')->associateBy('customer')->extra(function ($row) use ($source, &$called) {
            $row->relationPicker('products', 'Products', $source)->relation('children')->associateBy('customer')->extra(function ($row) use (&$called) {
                $row->text('note')->readOnly()->default('New');
                $row->select('choice')->remote(OptionSource::make(function (OptionContext $c) use (&$called) {
                    $called++;
                    expect($c->dependencies['name'])->toBe('Nested');

                    return User::query();
                })->label('name'), ['name' => ConditionPath::row('note')]);
            });
        });
    });
    $outer = ['path' => 'products', 'key' => (string) $first->id, 'id' => (string) $first->id];
    $inner = ['path' => 'products.*.products', 'key' => (string) $second->id, 'id' => (string) $second->id];
    $metadata = [['path' => 'products', 'rowContext' => [], 'depends' => (object) []], ['path' => 'products.*.products', 'rowContext' => [$outer], 'depends' => (object) []]];
    $parameters = ['rowContext' => json_encode([$outer, $inner]), 'ancestorSources' => json_encode($metadata)];
    formOptionsRequest($root->id, 'products.*.products.*.choice', $parameters)->assertOk();
    $alias = ['path' => 'products', 'key' => '0'.$first->id, 'id' => '0'.$first->id];
    $metadata[1]['rowContext'] = [$alias];
    $parameters['ancestorSources'] = json_encode($metadata);
    formOptionsRequest($root->id, 'products.*.products.*.choice', $parameters)->assertUnprocessable();
    $parameters['rowContext'] = json_encode([$alias, $inner]);
    formOptionsRequest($root->id, 'products.*.products.*.choice', $parameters)->assertUnprocessable();
    expect($called)->toBe(1);
});

it('hydrates later partitions and literal Tags only through their declared control endpoint', function () {
    $root = OptionsRecord::create(['shop_id' => 1, 'customer' => 'Root', 'status' => 'root']);
    $later = brumeUser(['username' => 'later', 'name' => 'Later label']);
    CodeItem::create(['code' => 'SKU-A', 'name' => 'String label']);
    formOptionsRoutes(function ($form) {
        $source = OptionSource::make(User::class)->label('name')->sections(['first' => 'First', 'later' => 'Later'], fn ($q, $section) => $q->where('username', $section === 'first' ? 'admin' : 'later'));
        $form->tags('tags')->remote($source);
        $form->select('strict')->remote($source);
        $form->multipleSelect('multiple')->remote($source);
        $form->relationPicker('picker', 'Picker', $source)->relation('children')->associateBy('customer');
        $form->tags('strings')->remote(OptionSource::make(CodeItem::class)->label('name'));
    });
    $values = [(string) $later->id, 'free text'];
    expect(formOptionsRequest($root->id, 'tags', ['value' => $values])->assertOk()->json('data'))->toBe([
        ['value' => (string) $later->id, 'label' => 'Later label'], ['value' => 'free text', 'label' => 'free text'],
    ]);
    foreach (['strict', 'multiple', 'picker'] as $field) {
        formOptionsRequest($root->id, $field, ['value' => $values, 'literals' => 'true'])->assertUnprocessable();
        expect(formOptionsRequest($root->id, $field, ['value' => [(string) $later->id]])->assertOk()->json('data.0.label'))->toBe('Later label');
    }
    expect(formOptionsRequest($root->id, 'strings', ['value' => ['SKU-A', 'a literal']])->assertOk()->json('data'))->toBe([
        ['value' => 'SKU-A', 'label' => 'String label'], ['value' => 'a literal', 'label' => 'a literal'],
    ]);
    expect(formOptionsRequest($root->id, 'tags', ['section' => 'first', 'value' => [(string) $later->id]])->assertOk()->json('data.0.label'))->toBe((string) $later->id);
    formOptionsRequest($root->id, 'tags', ['value' => range(1, 101)])->assertUnprocessable();
});

it('returns declared scoped picker authority without confusing source storage with intermediary defaults', function () {
    $root = OptionsRecord::create(['shop_id' => 1, 'customer' => 'Root', 'status' => 'root']);
    $allowed = brumeUser(['username' => 'allowed', 'name' => 'Stored source name']);
    $foreign = brumeUser(['username' => 'foreign', 'name' => 'Secret']);
    formOptionsRoutes(function ($form) use ($allowed) {
        $source = OptionSource::make(fn () => User::whereKey($allowed->id))->label('name');
        $form->relationPicker('owner', 'Owner', $source)->relation('sourceOwner')->multiple(false)->extra(function ($row) {
            $row->text('name')->default('new default')->readOnly(fn ($record) => $record !== null);
            $row->text('password');
        });
        $form->relationPicker('intermediaries', 'Intermediaries', $source)->relation('children')->associateBy('customer')->extra(fn ($row) => $row->text('note')->default('new note'));
        $form->repeater('tags', 'Rows', function ($row) {
            $row->switch('flag')->default(false);
            $row->number('zero')->default(0);
            $row->text('empty')->default('');
            $row->text('mapped')->attributes(['start', 'end'])->default(['ignored', 'ignored']);
        });
    });
    $response = formOptionsRequest($root->id, 'owner', ['value' => [(string) $allowed->id]])->assertOk();
    expect($response->json('data.0.authority.values.name'))->toBe('Stored source name')
        ->and($response->json('data.0.authority.values.password'))->toBeNull()
        ->and($response->json('data.0.authority.rowFields.0.fields.name.readOnly'))->toBeFalse()
        ->and(JsonSchema::errors($response->json(), 'PickerSearchResponse'))->toBe([]);
    expect(formOptionsRequest($root->id, 'owner', ['value' => [(string) $foreign->id]])->assertOk()->json('data'))->toBe([]);
    expect(formOptionsRequest($root->id, 'owner')->assertOk()->json('data.0'))->not->toHaveKey('authority');
    expect(formOptionsRequest($root->id, 'intermediaries', ['value' => [(string) $allowed->id]])->assertOk()->json('data.0.authority.values.note'))->toBe('new note');
    $page = $this->get('/option-records/'.$root->id.'/edit', ['X-Brume-Form' => '1'])->assertOk();
    $nodes = $page->json('page.form.nodes.2.field.nodes');
    expect(array_column(array_column($nodes, 'field'), 'defaultValue'))->toBe([false, 0, '', [null, null]])
        ->and(JsonSchema::errors($page->json(), 'PageEnvelope'))->toBe([]);
});

it('saves and reloads conditional chains with stored inactive authority at root and nested owners', function (string $condition) {
    $root = OptionsRecord::create(['shop_id' => 1, 'customer' => 'A', 'status' => 'root', 'is_active' => true, 'note' => 'old note', 'tags' => [['_brume' => ['key' => 'a'], 'visible' => true, 'kind' => 'A', 'note' => 'old nested']]]);
    formOptionsRoutes(function ($form) use ($condition) {
        $form->switch('is_active');
        $form->text('customer')->{$condition}(Conditions::eq(ConditionPath::root('is_active'), true));
        $form->text('note')->required()->showWhen(Conditions::eq(ConditionPath::root('customer'), 'B'));
        $form->repeater('tags', 'Rows', function ($row) use ($condition) {
            $row->switch('visible')->default(false);
            $row->text('kind')->default('A')->{$condition}(Conditions::eq(ConditionPath::row('visible'), true));
            $row->text('note')->required()->showWhen(Conditions::eq(ConditionPath::row('kind'), 'B'));
        });
    });
    $values = $this->get('/option-records/'.$root->id.'/edit', ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
    $values['customer'] = 'B';
    $values['note'] = '';
    $values['is_active'] = false;
    $values['tags'][0] = ['_brume' => ['key' => 'a'], 'visible' => false, 'kind' => 'B', 'note' => ''];
    $values['tags'][] = ['_brume' => ['key' => 'new'], 'visible' => false, 'kind' => 'B', 'note' => 'ignored'];
    $this->putJson('/option-records/'.$root->id, ['values' => $values])->assertOk();
    $fresh = $this->get('/option-records/'.$root->id.'/edit', ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
    expect($fresh['customer'])->toBe('A')->and($fresh['note'])->toBe('old note')
        ->and($fresh['tags'][0]['kind'])->toBe('A')->and($fresh['tags'][0]['note'])->toBe('old nested')
        ->and($fresh['tags'][1]['kind'])->toBe('A');
    $values['is_active'] = true;
    $values['note'] = 'new root';
    $values['tags'][0]['visible'] = true;
    $values['tags'][0]['note'] = 'new nested';
    $this->putJson('/option-records/'.$root->id, ['values' => $values])->assertOk();
    expect($root->fresh()->customer)->toBe('B')->and($root->fresh()->note)->toBe('new root')
        ->and($root->fresh()->tags[0]['kind'])->toBe('B')->and($root->fresh()->tags[0]['note'])->toBe('new nested');
})->with(['showWhen', 'enableWhen']);

it('persists later-section picker selections with extras and hydrates their labels after reload', function () {
    $root = OptionsRecord::create(['shop_id' => 999, 'customer' => 'Root', 'status' => 'root']);
    $later = brumeUser(['username' => 'later', 'name' => 'Archived eligible']);
    formOptionsRoutes(function ($form) {
        $source = OptionSource::make(User::class)->label('name')->sections(['current' => 'Current', 'archived' => 'Archived'], fn ($q, $section) => $q->where('username', $section === 'current' ? 'admin' : 'later'));
        $form->relationPicker('items', 'Items', $source)->relation('children')->associateBy('customer')->extra(fn ($row) => $row->text('note'));
    });
    expect(formOptionsRequest($root->id, 'items', ['section' => 'archived'])->assertOk()->json('data.0.value'))->toBe((string) $later->id);
    $this->putJson('/option-records/'.$root->id, ['values' => ['items' => [['id' => (string) $later->id, 'note' => 'retained extra']]]])->assertOk();
    $page = $this->get('/option-records/'.$root->id.'/edit', ['X-Brume-Form' => '1'])->assertOk();
    expect($page->json('page.values.items.0'))->toBe(['id' => (string) $later->id, 'note' => 'retained extra']);
    $hydrated = formOptionsRequest($root->id, 'items', ['value' => [$page->json('page.values.items.0.id')]])->assertOk();
    expect($hydrated->json('data.0.label'))->toBe('Archived eligible')
        ->and($hydrated->json('data.0.authority.values.note'))->toBe('retained extra')
        ->and(JsonSchema::errors($hydrated->json(), 'PickerSearchResponse'))->toBe([]);
    expect(formOptionsRequest($root->id, 'items', ['section' => 'current', 'value' => [(string) $later->id]])->assertOk()->json('data'))->toBe([]);
});
