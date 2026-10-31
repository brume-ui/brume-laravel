<?php

use Brume\Form\ConditionPath;
use Brume\Form\Conditions;
use Brume\Form\Form;
use Brume\Grid\Actions\Action;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\Models\CellItem;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

it('validates action inputs before transactions and passes only editable declared values', function () {
    $this->actingAs(brumeUser(), 'brume');
    $item = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    $seen = (object) ['input' => null, 'transactions' => 0];
    Event::listen(TransactionBeginning::class, function () use ($seen) {
        $seen->transactions++;
    });
    Route::middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeGrid('input-items', function () use (&$seen) {
        $grid = Grid::make(CellItem::class);
        $grid->column('customer');
        $grid->rowActions(Action::make('reason', 'Reason')->form(function (Form $form) {
            $form->text('reason')->required();
            $form->text('locked')->disabled();
            $form->tags('tags')->options(['a' => 'A']);
        })->handle(function ($models, $request, array $input) use (&$seen) {
            $seen->input = $input;
            $models->first()->update(['customer' => $input['reason']]);
        }));

        return $grid;
    }));
    $page = $this->get('/input-items')->assertOk()->viewData('page')['props']['envelope']['page'];
    expect($page['actions']['row'][0]['form']['fields'][0]['name'])->toBe('reason');
    $body = ['target' => 'row', 'ids' => [$item->id], 'input' => ['reason' => '']];
    $this->postJson('/input-items/_brume/actions/reason', $body)->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect($seen->transactions)->toBe(0)->and($seen->input)->toBeNull()->and($item->fresh()->customer)->toBe('Ada');
    $body['input'] = ['reason' => 'Bob', 'locked' => 'evil', 'unknown' => 'evil', 'tags' => ['']];
    $this->postJson('/input-items/_brume/actions/reason', $body)->assertUnprocessable()->assertJsonValidationErrors('tags.0');
    unset($body['input']['tags']);
    $this->postJson('/input-items/_brume/actions/reason', $body)->assertOk();
    expect($seen->input)->toBe(['reason' => 'Bob'])->and($item->fresh()->customer)->toBe('Bob');
});

it('accepts custom action tags regardless of suggestion configuration', function (?array $options) {
    $this->actingAs(brumeUser(), 'brume');
    $seen = (object) ['input' => null];
    Route::middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeGrid('tag-actions', function () use ($options, $seen) {
        $grid = Grid::make(CellItem::class);
        $grid->column('customer');
        $grid->tools(Action::make('tag', 'Tag')->form(function (Form $form) use ($options) {
            $field = $form->tags('tags');
            if ($options !== null) {
                $field->options($options);
            }
        })->handle(function ($models, $request, array $input) use ($seen) {
            $seen->input = $input;
        }));

        return $grid;
    }));
    $this->postJson('/tag-actions/_brume/actions/tag', ['target' => 'tool', 'ids' => [], 'input' => ['tags' => ['custom']]])->assertOk();
    expect($seen->input)->toBe(['tags' => ['custom']]);
})->with(['populated' => [['a' => 'A']], 'empty' => [[]], 'omitted' => [null]]);

it('applies shared flat action activity before passing input to the handler', function () {
    $this->actingAs(brumeUser(), 'brume');
    $seen = (object) ['input' => null];
    Route::middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeGrid('conditional-actions', function () use ($seen) {
        $grid = Grid::make(CellItem::class);
        $grid->column('customer');
        $grid->tools(Action::make('run', 'Run')->form(function (Form $form) {
            $form->text('mode')->default('off')->readOnly();
            $form->text('reason')->required()->enableWhen(Conditions::eq(ConditionPath::root('mode'), 'on'));
        })->handle(function ($models, $request, array $input) use ($seen) {
            $seen->input = $input;
        }));

        return $grid;
    }));
    $this->postJson('/conditional-actions/_brume/actions/run', ['target' => 'tool', 'ids' => [], 'input' => ['mode' => 'on', 'reason' => 'forged']])->assertOk();
    expect($seen->input)->toBe([]);
});

it('rejects object shaped flat action arrays and keeps dependent validation active', function () {
    $this->actingAs(brumeUser(), 'brume');
    Route::middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeGrid('shape-actions', function () {
        $grid = Grid::make(CellItem::class);
        $grid->column('customer');
        $grid->tools(Action::make('run', 'Run')->form(function (Form $form) {
            $form->tags('tags');
            $form->text('reason')->required()->showWhen(Conditions::not(Conditions::empty(Conditions::root('tags'))));
        })->handle(fn () => null));

        return $grid;
    }));
    $this->postJson('/shape-actions/_brume/actions/run', ['target' => 'tool', 'ids' => [], 'input' => ['tags' => (object) []]])->assertUnprocessable()->assertJsonValidationErrors(['tags', 'reason']);
    $this->postJson('/shape-actions/_brume/actions/run', ['target' => 'tool', 'ids' => [], 'input' => ['tags' => []]])->assertOk();
});
