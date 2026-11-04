<?php

use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Options\OptionSource;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Root → tiers (hard) → steps (soft) → notes (hard) and gifts (soft intermediaries of products). */
class CascadeRoot extends Model
{
    protected $table = 'cascade_roots';

    protected $guarded = [];

    public $timestamps = false;

    protected $connection = 'cascade_records';

    public function tiers()
    {
        return $this->hasMany(CascadeTier::class, 'root_id');
    }
}
class CascadeTier extends Model
{
    protected $table = 'cascade_tiers';

    protected $guarded = [];

    public $timestamps = false;

    protected $connection = 'cascade_records';

    public function steps()
    {
        return $this->hasMany(CascadeStep::class, 'tier_id');
    }

    public function products()
    {
        return $this->hasMany(CascadeProduct::class, 'tier_id');
    }

    public function owner()
    {
        return $this->belongsTo(CascadeProduct::class, 'owner_id');
    }

    public function parts()
    {
        return $this->belongsToMany(CascadeProduct::class, 'cascade_tier_products', 'tier_id', 'product_id')->withPivot('quantity');
    }
}
class CascadeStep extends Model
{
    use SoftDeletes;

    protected $table = 'cascade_steps';

    protected $guarded = [];

    public $timestamps = false;

    protected $connection = 'cascade_records';

    public function notes()
    {
        return $this->hasMany(CascadeNote::class, 'step_id');
    }

    public function gifts()
    {
        return $this->hasMany(CascadeGift::class, 'step_id');
    }
}
class CascadeNote extends Model
{
    protected $table = 'cascade_notes';

    protected $guarded = [];

    public $timestamps = false;

    protected $connection = 'cascade_records';
}
class CascadeGift extends Model
{
    use SoftDeletes;

    protected $table = 'cascade_gifts';

    protected $guarded = [];

    public $timestamps = false;

    protected $connection = 'cascade_records';
}
class CascadeProduct extends Model
{
    protected $table = 'cascade_products';

    protected $guarded = [];

    public $timestamps = false;

    protected $connection = 'cascade_records';
}

// Own connection, so file cleanup after the outer commit is observable (RefreshDatabase never commits).
beforeEach(function () {
    config(['database.connections.cascade_records' => getenv('BRUME_TEST_DB') === 'mysql'
        ? [...config('database.connections.mysql'), 'prefix' => 'cascade_physical_']
        : ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    config(['brume.uploads.connections' => [config('database.default'), 'cascade_records']]);
    DB::connection('cascade_records')->setTransactionManager(new DatabaseTransactionsManager);
    DB::usingConnection('cascade_records', function () {
        (require __DIR__.'/../../../database/migrations/2026_10_29_000000_create_brume_uploads_table.php')->up();
        (require __DIR__.'/../../../database/migrations/2026_10_31_000001_add_row_context_to_brume_uploads_table.php')->up();
    });
    Schema::connection('cascade_records')->create('cascade_roots', function ($t) {
        $t->id();
        $t->string('name')->default('root');
    });
    Schema::connection('cascade_records')->create('cascade_tiers', function ($t) {
        $t->id();
        $t->unsignedBigInteger('root_id');
        $t->unsignedBigInteger('owner_id')->nullable();
        $t->string('name')->default('tier');
        $t->string('file')->nullable();
    });
    Schema::connection('cascade_records')->create('cascade_steps', function ($t) {
        $t->id();
        $t->unsignedBigInteger('tier_id');
        $t->string('name')->default('step');
        $t->string('file')->nullable();
        $t->softDeletes();
    });
    Schema::connection('cascade_records')->create('cascade_notes', function ($t) {
        $t->id();
        $t->unsignedBigInteger('step_id');
        $t->string('body')->default('note');
        $t->string('file')->nullable();
    });
    Schema::connection('cascade_records')->create('cascade_gifts', function ($t) {
        $t->id();
        $t->unsignedBigInteger('step_id');
        $t->unsignedBigInteger('product_id');
        $t->integer('sold')->default(0);
        $t->string('file')->nullable();
        $t->softDeletes();
    });
    Schema::connection('cascade_records')->create('cascade_products', function ($t) {
        $t->id();
        $t->unsignedBigInteger('tier_id')->nullable();
        $t->string('name')->default('product');
        $t->string('file')->nullable();
    });
    Schema::connection('cascade_records')->create('cascade_tier_products', function ($t) {
        $t->unsignedBigInteger('tier_id');
        $t->unsignedBigInteger('product_id');
        $t->integer('quantity')->default(1);
        $t->primary(['tier_id', 'product_id']);
    });
    Storage::fake('public');
    $this->actingAs(brumeUser(), 'brume');
});

afterEach(function () {
    $connection = DB::connection('cascade_records');
    if ($connection->transactionLevel() > 0) {
        $connection->rollBack(0);
    }
    foreach (['cascade_tier_products', 'cascade_products', 'cascade_gifts', 'cascade_notes', 'cascade_steps', 'cascade_tiers', 'cascade_roots', 'brume_uploads'] as $table) {
        Schema::connection('cascade_records')->dropIfExists($table);
    }
    DB::purge('cascade_records');
});

function cascadeForm(CascadeRoot $root): Form
{
    $products = OptionSource::make(CascadeProduct::class)->label('name');
    $form = Form::make(CascadeRoot::class)->id('cascade')->forRecord($root)->urls(fn ($r) => ['edit' => '/cascade/'.$r->id]);
    $form->repeater('tiers', 'Tiers', function (Form $tier) use ($products) {
        $tier->text('name');
        $tier->file('file');
        $tier->relationPicker('owner', 'Owner', $products)->relation('owner')->multiple(false);
        $tier->relationPicker('products', 'Products', $products)->relation('products');
        $tier->relationPicker('parts', 'Parts', $products)->relation('parts')->extra(fn (Form $part) => $part->number('quantity'));
        $tier->repeater('steps', 'Steps', function (Form $step) use ($products) {
            $step->text('name');
            $step->file('file');
            $step->repeater('notes', 'Notes', fn (Form $note) => $note->file('file'))->relation('notes');
            $step->relationPicker('gifts', 'Gifts', $products)->relation('gifts')->associateBy('product_id')->restoreRemoved()
                ->extra(fn (Form $gift) => $gift->file('file'));
        })->relation('steps');
    })->relation('tiers');

    return $form;
}

function cascadeSave(CascadeRoot $root, array $values): void
{
    (new FormSaver)->save(cascadeForm($root), $root, Request::create('/', 'PUT', ['values' => $values]));
}

function cascadeRow(Model $row, array $values = []): array
{
    return ['_brume' => ['key' => 'record:'.$row->getKey(), 'id' => (string) $row->getKey()]] + $values;
}

/** Two tiers, each with one step holding a note and a gift; tier one also owns, picks and attaches products. */
function cascadeGraph(): array
{
    $root = CascadeRoot::create();
    $owner = CascadeProduct::create(['name' => 'owner', 'file' => 'files/owner.txt']);
    [$tierA, $tierB] = [$root->tiers()->create(['owner_id' => $owner->id, 'file' => 'files/tier-a.txt']), $root->tiers()->create()];
    $picked = CascadeProduct::create(['name' => 'picked', 'tier_id' => $tierA->id, 'file' => 'files/picked.txt']);
    $part = CascadeProduct::create(['name' => 'part']);
    $tierA->parts()->attach($part->id, ['quantity' => 3]);
    $steps = [];
    foreach ([$tierA, $tierB] as $index => $tier) {
        $step = $tier->steps()->create(['file' => "files/step-{$index}.txt"]);
        $step->notes()->create(['file' => "files/note-{$index}.txt"]);
        $step->gifts()->create(['product_id' => $part->id, 'sold' => 7 + $index, 'file' => "files/gift-{$index}.txt"]);
        $steps[] = $step;
    }
    foreach (['owner', 'tier-a', 'picked', 'step-0', 'step-1', 'note-0', 'note-1', 'gift-0', 'gift-1'] as $name) {
        Storage::disk('public')->put("files/{$name}.txt", $name);
    }

    return compact('root', 'owner', 'tierA', 'tierB', 'picked', 'part', 'steps');
}

it('removing a hard-deleted row removes its owned nested rows: soft where they soft-delete, pivots detached, sources untouched', function () {
    ['root' => $root, 'owner' => $owner, 'tierA' => $tierA, 'tierB' => $tierB, 'picked' => $picked, 'part' => $part, 'steps' => $steps] = cascadeGraph();

    cascadeSave($root, ['tiers' => [cascadeRow($tierB)]]);

    expect(CascadeTier::whereKey($tierA->id)->exists())->toBeFalse()
        // The removed tier's step and its gift are trashed with their counters; its note is gone.
        ->and(CascadeStep::withTrashed()->findOrFail($steps[0]->id)->trashed())->toBeTrue()
        ->and(CascadeGift::withTrashed()->where('step_id', $steps[0]->id)->get()->map(fn ($gift) => [$gift->trashed(), $gift->sold])->all())->toBe([[true, 7]])
        ->and(CascadeNote::where('step_id', $steps[0]->id)->exists())->toBeFalse()
        ->and(DB::connection('cascade_records')->table('cascade_tier_products')->where('tier_id', $tierA->id)->exists())->toBeFalse()
        // Source rows are not owned: a picked product keeps its (now dangling) key, the owner and part stay.
        ->and($picked->fresh()->tier_id)->toBe($tierA->id)
        ->and($owner->fresh())->not->toBeNull()->and($part->fresh())->not->toBeNull()
        // The kept tier's rows are untouched.
        ->and(CascadeStep::findOrFail($steps[1]->id)->trashed())->toBeFalse()
        ->and(CascadeNote::where('step_id', $steps[1]->id)->count())->toBe(1)
        ->and(CascadeGift::where('step_id', $steps[1]->id)->count())->toBe(1);

    // Hard-deleted rows (tier, note) lose their files; trashed rows and source rows keep theirs.
    Storage::disk('public')->assertMissing(['files/tier-a.txt', 'files/note-0.txt']);
    Storage::disk('public')->assertExists(['files/step-0.txt', 'files/gift-0.txt', 'files/owner.txt', 'files/picked.txt', 'files/step-1.txt', 'files/note-1.txt', 'files/gift-1.txt']);
});

it('removing a soft-deleted row trashes its soft gifts and hard-deletes its notes', function () {
    ['root' => $root, 'tierA' => $tierA, 'tierB' => $tierB, 'steps' => $steps] = cascadeGraph();

    cascadeSave($root, ['tiers' => [cascadeRow($tierA, ['steps' => []]), cascadeRow($tierB)]]);

    expect(CascadeStep::withTrashed()->findOrFail($steps[0]->id)->trashed())->toBeTrue()
        ->and(CascadeGift::withTrashed()->where('step_id', $steps[0]->id)->get()->map(fn ($gift) => [$gift->trashed(), $gift->sold])->all())->toBe([[true, 7]])
        ->and(CascadeNote::where('step_id', $steps[0]->id)->exists())->toBeFalse()
        ->and(CascadeTier::whereKey($tierA->id)->exists())->toBeTrue()
        ->and(DB::connection('cascade_records')->table('cascade_tier_products')->where('tier_id', $tierA->id)->value('quantity'))->toBe(3)
        ->and(CascadeGift::where('step_id', $steps[1]->id)->count())->toBe(1);
    Storage::disk('public')->assertMissing('files/note-0.txt');
    Storage::disk('public')->assertExists(['files/step-0.txt', 'files/gift-0.txt', 'files/tier-a.txt']);
});

it('removing a gift intermediary leaves its siblings and restores it with its counters', function () {
    ['root' => $root, 'tierA' => $tierA, 'tierB' => $tierB, 'part' => $part, 'steps' => $steps] = cascadeGraph();
    $gift = CascadeGift::where('step_id', $steps[0]->id)->firstOrFail();

    cascadeSave($root, ['tiers' => [cascadeRow($tierA, ['steps' => [cascadeRow($steps[0], ['gifts' => []])]]), cascadeRow($tierB)]]);
    expect($gift->fresh()->trashed())->toBeTrue()->and(CascadeNote::where('step_id', $steps[0]->id)->count())->toBe(1);

    cascadeSave($root, ['tiers' => [cascadeRow($tierA, ['steps' => [cascadeRow($steps[0], ['gifts' => [['id' => (string) $part->id]]])]]), cascadeRow($tierB)]]);
    expect($gift->fresh()->trashed())->toBeFalse()->and($gift->fresh()->sold)->toBe(7)->and(CascadeGift::withTrashed()->where('step_id', $steps[0]->id)->count())->toBe(1);
    Storage::disk('public')->assertExists('files/gift-0.txt');
});
