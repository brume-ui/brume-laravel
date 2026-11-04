<?php

use Brume\Http\Middleware\HandleBrumeRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\PromotionController;
use Workbench\App\Models\CatalogProduct;
use Workbench\App\Models\Promotion;
use Workbench\App\Models\PromotionAction;
use Workbench\App\Models\PromotionCondition;
use Workbench\App\Models\PromotionFreebie;
use Workbench\Database\Seeders\CatalogSeeder;

beforeEach(function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::prefix('admin')->middleware([...config('brume.route.middleware'), HandleBrumeRequests::class])->group(function () {
        Route::brumeResource('promotions', PromotionController::class)->names('brume.promotions');
    });
    Route::getRoutes()->refreshNameLookups();
    $this->seed(CatalogSeeder::class);
});

function promotionValues($test): array
{
    return $test->getJson('/admin/promotions/1/edit', ['X-Brume-Form' => '1'])->assertOk()->json('page.values');
}

function promotionProduct(int $n): int
{
    return (int) CatalogProduct::where('identifier', sprintf('DEMO-%03d', $n))->value('id');
}

/** Every row of the promotion graph, trashed rows and counters included. */
function promotionGraph(): array
{
    return collect(['promotions', 'promotion_conditions', 'promotion_actions', 'promotion_freebies'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
        ->all();
}

/** Gives the 1000 threshold's action four gifts; the fourth has handed out 3 (already) + 7 (current). */
function promotionFourGifts(): PromotionFreebie
{
    PromotionFreebie::create(['action_id' => 2, 'product_id' => promotionProduct(13), 'position' => 4]);

    return PromotionFreebie::create(['action_id' => 2, 'product_id' => promotionProduct(14), 'is_limited' => true, 'given_limit' => 10, 'given_count' => 7, 'already_given_count' => 3, 'position' => 5]);
}

function promotionErrorKeys($response): array
{
    return array_keys($response->assertUnprocessable()->json('errors'));
}

it('treats differently formatted equal thresholds as duplicates', function () {
    foreach (['500', '500.0', '500.00'] as $amount) {
        $values = promotionValues($this);
        $values['conditions'][1]['amount'] = $amount;
        expect(promotionErrorKeys($this->putJson('/admin/promotions/1', ['values' => $values])))->toBe(['conditions.1.amount']);
    }
    expect(PromotionCondition::findOrFail(2)->amount)->toBe('1000.00');

    $values = promotionValues($this);
    $values['conditions'][1]['amount'] = '500.01';
    $this->putJson('/admin/promotions/1', ['values' => $values])->assertOk();
    expect(PromotionCondition::findOrFail(2)->amount)->toBe('500.01');
});

it('reports a gift limit below its given count at the exact nested path and rolls back the whole graph', function () {
    promotionFourGifts();
    $before = promotionGraph();

    $values = promotionValues($this);
    expect(array_column($values['conditions'][1]['actions'][0]['freebies'], 'id'))->toHaveCount(4)
        ->and($values['conditions'][1]['actions'][0]['freebies'][3])->not->toHaveKeys(['given_count', 'already_given_count']);
    $values['name'] = '應回滾';
    $values['conditions'][0]['amount'] = '600.00';
    $values['conditions'][0]['actions'][0]['freebies'][0] = array_replace($values['conditions'][0]['actions'][0]['freebies'][0], ['is_limited' => true, 'given_limit' => 9]);
    $values['conditions'][0]['actions'][0]['freebies'][] = ['id' => (string) promotionProduct(20), 'is_limited' => false];
    $values['conditions'][1]['actions'][0]['freebies'][3]['given_limit'] = 2;

    $response = $this->putJson('/admin/promotions/1', ['values' => $values]);
    expect(promotionErrorKeys($response))->toBe(['conditions.1.actions.0.freebies.3.given_limit'])
        ->and($response->json('errors')['conditions.1.actions.0.freebies.3.given_limit'][0])->toBe('限量數不可小於已送出的 3 份')
        ->and(promotionGraph())->toBe($before);

    $values['conditions'][1]['actions'][0]['freebies'][3]['given_limit'] = 3;
    $this->putJson('/admin/promotions/1', ['values' => $values])->assertOk();
    $gift = PromotionFreebie::where('action_id', 2)->where('product_id', promotionProduct(14))->sole();
    expect($gift->only(['given_limit', 'given_count', 'already_given_count']))->toBe(['given_limit' => 3, 'given_count' => 7, 'already_given_count' => 3])
        ->and(PromotionCondition::findOrFail(1)->amount)->toBe('600.00')
        ->and(PromotionFreebie::where('action_id', 1)->count())->toBe(2);
});

it('rejects a forged counter and a non-integer or zero limit on a new gift', function () {
    $values = promotionValues($this);
    $values['conditions'][0]['actions'][0]['freebies'][] = ['id' => (string) promotionProduct(20), 'is_limited' => true, 'given_limit' => 0, 'given_count' => 99, 'already_given_count' => 99];
    expect(promotionErrorKeys($this->putJson('/admin/promotions/1', ['values' => $values])))->toBe(['conditions.0.actions.0.freebies.1.given_limit']);

    $values['conditions'][0]['actions'][0]['freebies'][1]['given_limit'] = 1;
    $this->putJson('/admin/promotions/1', ['values' => $values])->assertOk();
    expect(PromotionFreebie::where('action_id', 1)->where('product_id', promotionProduct(20))->sole()->only(['given_limit', 'given_count', 'already_given_count']))
        ->toBe(['given_limit' => 1, 'given_count' => 0, 'already_given_count' => 0]);
});

it('keeps ids and counters through save, reorder and soft remove then reselect', function () {
    $gift = promotionFourGifts();
    $counters = ['given_count' => 7, 'already_given_count' => 3];

    // Reorder thresholds and the 1000 threshold's gifts: ids stay, positions follow the submitted order.
    $values = promotionValues($this);
    $values['conditions'] = array_reverse($values['conditions']);
    $values['conditions'][0]['actions'][0]['freebies'] = array_reverse($values['conditions'][0]['actions'][0]['freebies']);
    $this->putJson('/admin/promotions/1', ['values' => $values])->assertOk();
    expect(Promotion::findOrFail(1)->conditions()->pluck('id')->all())->toBe([2, 1])
        ->and(PromotionAction::findOrFail(2)->freebies()->pluck('id')->all())->toBe([$gift->id, $gift->id - 1, 3, 2])
        ->and(PromotionFreebie::count())->toBe(5)
        ->and($gift->fresh()->only(array_keys($counters)))->toBe($counters);

    // Back to ascending thresholds, then remove the counted gift: it is soft-deleted with its counters.
    $values = promotionValues($this);
    $values['conditions'] = array_reverse($values['conditions']);
    $values['conditions'][1]['actions'][0]['freebies'] = array_values(array_filter($values['conditions'][1]['actions'][0]['freebies'], fn ($row) => $row['id'] !== (string) $gift->product_id));
    $this->putJson('/admin/promotions/1', ['values' => $values])->assertOk();
    expect($gift->fresh()->trashed())->toBeTrue()->and($gift->fresh()->only(array_keys($counters)))->toBe($counters);

    // Reselecting under the same action matches the trashed row: its stored count is the floor.
    $values = promotionValues($this);
    $values['conditions'][1]['actions'][0]['freebies'][] = ['id' => (string) $gift->product_id, 'is_limited' => true, 'given_limit' => 2];
    $response = $this->putJson('/admin/promotions/1', ['values' => $values]);
    expect(promotionErrorKeys($response))->toBe(['conditions.1.actions.0.freebies.3.given_limit'])
        ->and($response->json('errors')['conditions.1.actions.0.freebies.3.given_limit'][0])->toBe('限量數不可小於已送出的 3 份')
        ->and($gift->fresh()->trashed())->toBeTrue();

    $values['conditions'][1]['actions'][0]['freebies'][3]['given_limit'] = 5;
    $this->putJson('/admin/promotions/1', ['values' => $values])->assertOk();
    $restored = $gift->fresh();
    expect($restored->trashed())->toBeFalse()
        ->and($restored->only([...array_keys($counters), 'given_limit', 'action_id']))->toBe([...$counters, 'given_limit' => 5, 'action_id' => 2])
        ->and(PromotionFreebie::withTrashed()->where('product_id', $gift->product_id)->count())->toBe(1);

    // Moving it to another action creates a new row there and leaves the counted row trashed.
    $values = promotionValues($this);
    $values['conditions'][1]['actions'][0]['freebies'] = array_slice($values['conditions'][1]['actions'][0]['freebies'], 0, 3);
    $values['conditions'][0]['actions'][0]['freebies'][] = ['id' => (string) $gift->product_id, 'is_limited' => true, 'given_limit' => 1];
    $this->putJson('/admin/promotions/1', ['values' => $values])->assertOk();
    $moved = PromotionFreebie::where('action_id', 1)->where('product_id', $gift->product_id)->sole();
    expect($moved->id)->not->toBe($gift->id)
        ->and($moved->only(['given_limit', 'given_count', 'already_given_count']))->toBe(['given_limit' => 1, 'given_count' => 0, 'already_given_count' => 0])
        ->and($gift->fresh()->trashed())->toBeTrue()
        ->and($gift->fresh()->only([...array_keys($counters), 'action_id']))->toBe([...$counters, 'action_id' => 2]);
});

it('validates choose counts against the current action only', function () {
    // The 1000 threshold's action offers 2 gifts with choose 1.
    $values = promotionValues($this);
    $values['conditions'][1]['actions'][0]['choose_count'] = 3;
    expect(promotionErrorKeys($this->putJson('/admin/promotions/1', ['values' => $values])))->toBe(['conditions.1.actions.0.choose_count']);

    $values['conditions'][1]['actions'][] = [
        '_brume' => ['key' => 'new-action'], 'strategy' => 'choose', 'choose_count' => 3,
        'freebies' => array_map(fn ($n) => ['id' => (string) promotionProduct($n), 'is_limited' => false], [20, 21, 22, 23]),
    ];
    expect(promotionErrorKeys($this->putJson('/admin/promotions/1', ['values' => $values])))->toBe(['conditions.1.actions.0.choose_count']);
    expect(PromotionAction::count())->toBe(2);

    $values['conditions'][1]['actions'][0]['choose_count'] = 2;
    $this->putJson('/admin/promotions/1', ['values' => $values])->assertOk();
    $added = PromotionAction::where('condition_id', 2)->whereKeyNot(2)->sole();
    expect($added->only(['strategy', 'choose_count', 'position']))->toBe(['strategy' => 'choose', 'choose_count' => 3, 'position' => 1])
        ->and($added->freebies()->count())->toBe(4)
        ->and(PromotionAction::findOrFail(2)->choose_count)->toBe(2);

    // A row that omits freebies is checked against the action's stored gifts (2), not the submitted ones.
    // required() on the picker still rejects the omission itself, so nothing is written.
    $values = promotionValues($this);
    unset($values['conditions'][1]['actions'][0]['freebies']);
    $values['conditions'][1]['actions'][0]['choose_count'] = 3;
    $response = $this->putJson('/admin/promotions/1', ['values' => $values]);
    expect($response->assertUnprocessable()->json('errors')['conditions.1.actions.0.choose_count'])->toBe(['任選數量不可大於贈品數 2 樣']);
    $values['conditions'][1]['actions'][0]['choose_count'] = 2;
    expect(promotionErrorKeys($this->putJson('/admin/promotions/1', ['values' => $values])))->toBe(['conditions.1.actions.0.freebies']);
    expect(PromotionAction::findOrFail(2)->choose_count)->toBe(2)->and(PromotionAction::findOrFail(2)->freebies()->count())->toBe(2);

    // An explicit empty list counts as zero gifts (and is itself rejected).
    $values = promotionValues($this);
    $values['conditions'][1]['actions'][0]['freebies'] = [];
    $response = $this->putJson('/admin/promotions/1', ['values' => $values]);
    expect(promotionErrorKeys($response))->toBe(['conditions.1.actions.0.choose_count', 'conditions.1.actions.0.freebies'])
        ->and($response->json('errors')['conditions.1.actions.0.choose_count'])->toBe(['任選數量不可大於贈品數 0 樣']);

    // Switching to 全部送 drops the inactive choose count from the write.
    $values = promotionValues($this);
    $values['conditions'][1]['actions'][0] = array_replace($values['conditions'][1]['actions'][0], ['strategy' => 'all', 'choose_count' => 99]);
    $this->putJson('/admin/promotions/1', ['values' => $values])->assertOk();
    expect(PromotionAction::findOrFail(2)->only(['strategy', 'choose_count']))->toBe(['strategy' => 'all', 'choose_count' => 2]);
});

it('requires at least one threshold, one action per threshold and one gift per action', function () {
    $values = promotionValues($this);
    $values['conditions'][0]['actions'][0]['freebies'] = [];
    expect(promotionErrorKeys($this->putJson('/admin/promotions/1', ['values' => $values])))->toBe(['conditions.0.actions.0.freebies']);

    $values = promotionValues($this);
    $values['conditions'][0]['actions'] = [];
    expect(promotionErrorKeys($this->putJson('/admin/promotions/1', ['values' => $values])))->toBe(['conditions.0.actions']);

    $values = promotionValues($this);
    $values['conditions'] = [];
    expect(promotionErrorKeys($this->putJson('/admin/promotions/1', ['values' => $values])))->toBe(['conditions']);
    expect(PromotionFreebie::count())->toBe(3);
});

it('creates a promotion with a threshold, an action and a gift', function () {
    $form = $this->getJson('/admin/promotions/create', ['X-Brume-Form' => '1'])->assertOk();
    $encoded = json_encode($form->json('page.form'));
    expect($encoded)->toContain('PromotionCalculator')->not->toContain('given_count')->not->toContain('already_given_count');

    $values = array_replace($form->json('page.values'), [
        'name' => '新活動', 'period' => ['start' => '2026-12-01T00:00', 'end' => '2026-12-31T23:59'], 'is_enabled' => true,
        'conditions' => [['_brume' => ['key' => 'c1'], 'amount' => '800', 'actions' => [[
            '_brume' => ['key' => 'a1'], 'strategy' => 'all',
            'freebies' => [['id' => (string) promotionProduct(30), 'is_limited' => false]],
        ]]]],
    ]);
    $id = $this->postJson('/admin/promotions', ['values' => $values])->assertOk()->json('id');
    $promotion = Promotion::findOrFail($id);
    $condition = $promotion->conditions()->sole();
    expect($promotion->starts_at->format('Y-m-d H:i'))->toBe('2026-12-01 00:00')
        ->and($condition->amount)->toBe('800.00')
        ->and($condition->actions()->sole()->freebies()->sole()->product_id)->toBe(promotionProduct(30));
});

it('lists promotions with period, enabled switch and threshold count', function () {
    $page = $this->get('/admin/promotions')->assertOk()->viewData('page')['props']['envelope']['page'];
    expect(array_column($page['columns'], 'key'))->toContain('name', 'period', 'is_enabled', 'conditions_count')
        ->and($page['rows'])->toHaveCount(1);
});

it('deleting a promotion takes its thresholds, actions and gift rows with it', function () {
    $this->postJson('/admin/promotions/_brume/actions/delete', ['target' => 'row', 'ids' => [1]])->assertOk();

    expect(array_map('count', promotionGraph()))->toBe(['promotions' => 0, 'promotion_conditions' => 0, 'promotion_actions' => 0, 'promotion_freebies' => 0])
        ->and(CatalogProduct::find(promotionProduct(11))->liveGifts()->exists())->toBeFalse();
});
