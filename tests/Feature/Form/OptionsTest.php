<?php

use Brume\Form\ConditionPath;
use Brume\Form\Form;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Models\User;
use Brume\Options\OptionContext;
use Brume\Options\OptionSource;
use Brume\Tests\Support\JsonSchema;
use Brume\Tests\Support\Models\CellItem;
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
