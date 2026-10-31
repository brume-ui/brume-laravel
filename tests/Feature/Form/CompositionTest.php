<?php

use Brume\Form\Conditions;
use Brume\Form\DeclarationRegistry;
use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Options\OptionSource;
use Brume\Tests\Support\JsonSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\CompositionController;
use Workbench\App\Models\Composition;
use Workbench\App\Models\CompositionCatalog;
use Workbench\App\Models\CompositionNode;
use Workbench\Database\Seeders\CompositionSeeder;

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeResource('compositions', CompositionController::class));
    Route::getRoutes()->refreshNameLookups();
    $this->seed(CompositionSeeder::class);
});

it('renders the composition contract and round trips custom mapped JSON and relation fields', function () {
    $page = $this->get('/compositions/1/edit', ['X-Brume-Form' => '1'])->assertOk();
    expect(JsonSchema::errors($page->json(), 'PageEnvelope'))->toBe([]);
    $values = $page->json('page.values');
    expect($values['tiers'][0]['thresholds'][0]['gifts'][0]['quantity'])->toBe(2);
    $values['address']['address'] = '中山南路2號';
    $values['notes'][0]['message'] = 'JSON round trip';
    $values['tiers'][0]['thresholds'][0]['gifts'][0]['quantity'] = 4;
    $this->putJson('/compositions/1', ['values' => $values])->assertOk();
    $fresh = $this->get('/compositions/1/edit', ['X-Brume-Form' => '1'])->assertOk();
    expect($fresh->json('page.values.address.address'))->toBe('中山南路2號')
        ->and($fresh->json('page.values.notes.0.message'))->toBe('JSON round trip')
        ->and($fresh->json('page.values.tiers.0.thresholds.0.gifts.0.quantity'))->toBe(4);
    expect(Composition::find(1)->tiers()->first()->thresholds()->first()->gifts()->first()->counter)->toBe(37);
});

it('rejects nested picker extras atomically and exposes scalar and array Grid filter contracts', function () {
    $page = $this->get('/compositions/1/edit', ['X-Brume-Form' => '1'])->assertOk();
    $values = $page->json('page.values');
    $values['title'] = 'must roll back';
    $values['tiers'][0]['thresholds'][0]['gifts'][0]['quantity'] = 0;
    $this->putJson('/compositions/1', ['values' => $values])->assertUnprocessable()->assertJsonValidationErrors('tiers.0.thresholds.0.gifts.0.quantity');
    expect(Composition::find(1)->title)->toBe('關聯組合 1');
    $list = $this->get('/compositions', ['X-Brume' => '1'])->assertOk();
    // Both filter kinds are declared against existing catalog IDs, not relation-object values.
    expect(str_contains($list->getContent(), 'multipleSelectTable'))->toBeTrue()
        ->and(str_contains($list->getContent(), 'selectTable'))->toBeTrue();
});

it('persists retained intermediary and pivot selection order without overwriting counters or trusting forged position', function () {
    $root = Composition::find(1);
    $threshold = $root->tiers()->first()->thresholds()->first();
    $first = $threshold->gifts()->first();
    $second = $threshold->gifts()->create(['catalog_id' => 2, 'quantity' => 1, 'position' => 7]);
    $root->featured()->attach(1, ['quantity' => 1, 'position' => 9]);
    $root->featured()->attach(2, ['quantity' => 1, 'position' => 3]);
    $values = $this->get('/compositions/1/edit', ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
    $values['featured'] = [['id' => '1', 'quantity' => 1, 'position' => 999], ['id' => '2', 'quantity' => 2]];
    $values['tiers'][0]['thresholds'][0]['gifts'] = [['id' => '2', 'quantity' => 1, 'position' => 999], ['id' => '1', 'quantity' => 2]];
    $this->putJson('/compositions/1', ['values' => $values])->assertOk();
    $fresh = $this->get('/compositions/1/edit', ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
    expect(array_column($fresh['featured'], 'id'))->toBe(['1', '2'])
        ->and(array_column($fresh['tiers'][0]['thresholds'][0]['gifts'], 'id'))->toBe(['2', '1'])
        ->and($first->fresh()->position)->toBe(1)->and($first->fresh()->counter)->toBe(37)
        ->and($second->fresh()->position)->toBe(0)->and($threshold->gifts()->where('catalog_id', 2)->first()->id)->toBe($second->id)
        ->and($root->featured()->find(1)->pivot->getAttribute('position'))->toBeNull();
    // Raw relation does not opt into withPivot(position); verify actual persisted pivot metadata.
    expect(DB::table('composition_featured')->where('composition_id', 1)->orderBy('position')->pluck('catalog_id')->all())->toBe([1, 2]);
    $second->delete();
    $this->putJson('/compositions/1', ['values' => $fresh])->assertOk();
    expect($second->fresh()->deleted_at)->toBeNull()->and($second->fresh()->position)->toBe(0)->and($second->fresh()->counter)->toBe(37);
    $fresh['featured'] = array_reverse($fresh['featured']);
    $fresh['tiers'][0]['thresholds'][0]['gifts'][0]['quantity'] = 0;
    $this->putJson('/compositions/1', ['values' => $fresh])->assertUnprocessable();
    expect(DB::table('composition_featured')->where('composition_id', 1)->orderBy('position')->pluck('catalog_id')->all())->toBe([1, 2]);
});

it('rejects picker order mapping for source-owned relations and identity or extra collisions', function () {
    $source = OptionSource::make(CompositionCatalog::class)->label('name');
    foreach ([['owner', false], ['candidates', true], ['featured', false]] as [$relation, $multiple]) {
        $form = Form::make(Composition::class);
        $form->relationPicker($relation, $relation, $source)->relation($relation)->multiple($multiple)->orderColumn('position');
        expect(fn () => new DeclarationRegistry($form->fields(), new Composition))->toThrow(InvalidArgumentException::class);
    }
    foreach (['id', 'composition_id', 'catalog_id'] as $column) {
        $form = Form::make(Composition::class);
        $form->relationPicker('featured', 'Featured', $source)->relation('featured')->orderColumn($column);
        expect(fn () => new DeclarationRegistry($form->fields(), new Composition))->toThrow(InvalidArgumentException::class);
    }
    foreach (['id', 'node_id', 'catalog_id'] as $column) {
        $form = Form::make(CompositionNode::class);
        $form->relationPicker('gifts', 'Gifts', $source)->relation('gifts')->associateBy('catalog_id')->orderColumn($column);
        expect(fn () => new DeclarationRegistry($form->fields(), new CompositionNode))->toThrow(InvalidArgumentException::class);
    }
    $form = Form::make(Composition::class);
    $form->relationPicker('featured', 'Featured', $source)->relation('featured')->orderColumn('position')->extra(fn ($row) => $row->number('alias')->attribute('position'));
    expect(fn () => new DeclarationRegistry($form->fields(), new Composition))->toThrow(InvalidArgumentException::class);
});

it('preserves exact omitted readonly and inactive picker ordering metadata', function () {
    $root = Composition::find(1);
    $root->featured()->attach(1, ['quantity' => 1, 'position' => 9]);
    $root->featured()->attach(2, ['quantity' => 1, 'position' => 3]);
    foreach (['omitted', 'readonly', 'inactive'] as $mode) {
        $form = Form::make(Composition::class)->urls(fn ($r) => ['edit' => '/compositions/'.$r->id]);
        $form->text('title');
        $form->switch('delivery');
        $field = $form->relationPicker('featured', 'Featured', OptionSource::make(CompositionCatalog::class)->label('name'))
            ->relation('featured')->orderColumn('position')->extra(fn ($row) => $row->number('quantity'));
        if ($mode === 'readonly') {
            $field->readOnly();
        } elseif ($mode === 'inactive') {
            $field->enableWhen(Conditions::eq(Conditions::root('delivery'), true));
        }
        $values = ['title' => 'retained'];
        if ($mode !== 'omitted') {
            $values['featured'] = [['id' => '1', 'quantity' => 5], ['id' => '2', 'quantity' => 5]];
        }
        (new FormSaver)->save($form, $root, Request::create('/', 'PUT', ['values' => $values]));
        expect(DB::table('composition_featured')->where('composition_id', 1)->orderBy('catalog_id')->pluck('position')->all())->toBe([9, 3]);
        expect(array_column($field->valueFor($root->fresh()), 'id'))->toBe(['2', '1']);
    }
});

it('creates a new tier threshold and gift through the public recursive resource without saving hooks', function () {
    $values = $this->get('/compositions/1/edit', ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
    $values['tiers'][] = [
        '_brume' => ['key' => 'new-tier'], 'name' => '第二級',
        'thresholds' => [[
            '_brume' => ['key' => 'new-threshold'], 'name' => '新門檻', 'amount' => '200.00',
            'gifts' => [['id' => '2', 'quantity' => 1]],
        ]],
    ];
    $this->putJson('/compositions/1', ['values' => $values])->assertOk();
    $root = Composition::find(1);
    $tier = $root->tiers()->where('name', '第二級')->first();
    expect($tier->name)->toBe('第二級')->and($tier->position)->toBe(1)
        ->and($tier->thresholds()->first()->gifts()->first()->catalog_id)->toBe(2);
    $fresh = $this->get('/compositions/1/edit', ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
    expect($fresh['tiers'][1]['thresholds'][0]['gifts'][0]['id'])->toBe('2');
});

it('hydrates restored intermediary authority without restoring or exposing its undeclared counters', function () {
    $root = Composition::find(1);
    $tier = $root->tiers()->first();
    $threshold = $tier->thresholds()->first();
    $gift = $threshold->gifts()->first();
    $gift->delete();
    $params = ['field' => 'tiers.*.thresholds.*.gifts', 'depends' => '{"category":"A"}', 'value' => [(string) $gift->catalog_id], 'rowContext' => json_encode([
        ['path' => 'tiers', 'key' => 'record:'.$tier->id, 'id' => (string) $tier->id],
        ['path' => 'tiers.*.thresholds', 'key' => 'record:'.$threshold->id, 'id' => (string) $threshold->id],
    ])];
    $response = $this->getJson('/compositions/1/_brume/form-options?'.http_build_query($params))->assertOk();
    expect($response->json('data.0.authority.values.quantity'))->toBe(2)
        ->and($response->json('data.0.authority.values'))->not->toHaveKey('counter')
        ->and($gift->fresh()->counter)->toBe(37)->and($gift->fresh()->deleted_at)->not->toBeNull()
        ->and(JsonSchema::errors($response->json(), 'PickerSearchResponse'))->toBe([]);
});
