<?php

use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Form\Relations\RelationPlanner;
use Brume\Form\Relations\RelationSynchronizer;
use Brume\Options\OptionSource;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
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

    public function marks()
    {
        return $this->hasMany(CascadeMark::class, 'gift_id');
    }

    public function memos()
    {
        return $this->hasMany(CascadeMemo::class, 'gift_id');
    }

    public function bundle()
    {
        return $this->belongsToMany(CascadeProduct::class, 'cascade_gift_products', 'gift_id', 'product_id')->withPivot('quantity');
    }

    public function extras()
    {
        return $this->hasMany(CascadeExtra::class, 'gift_id');
    }

    public function sets()
    {
        return $this->belongsToMany(CascadeProduct::class, 'cascade_gift_sets', 'gift_id', 'product_id')->using(CascadeSetPivot::class);
    }
}
/** A custom pivot: detaching it must go through the model, so its events fire. */
class CascadeSetPivot extends Pivot
{
    public static array $deleted = [];

    protected $table = 'cascade_gift_sets';

    protected $connection = 'cascade_records';

    protected static function booted(): void
    {
        static::deleted(fn (self $pivot) => static::$deleted[] = [$pivot->gift_id, $pivot->product_id]);
    }
}
/** A restorable intermediary nested in a restorable intermediary. */
class CascadeExtra extends Model
{
    use SoftDeletes;

    protected $table = 'cascade_extras';

    protected $guarded = [];

    public $timestamps = false;

    protected $connection = 'cascade_records';
}
/** Soft rows under a gift, with soft stamps under them: restored with the gift. */
class CascadeMark extends Model
{
    use SoftDeletes;

    protected $table = 'cascade_marks';

    protected $guarded = [];

    public $timestamps = false;

    protected $connection = 'cascade_records';

    public function stamps()
    {
        return $this->hasMany(CascadeStamp::class, 'mark_id');
    }
}
class CascadeStamp extends Model
{
    use SoftDeletes;

    protected $table = 'cascade_stamps';

    protected $guarded = [];

    public $timestamps = false;

    protected $connection = 'cascade_records';
}
/** Hard rows under a gift: kept on a trashed gift, deleted with a purged one. */
class CascadeMemo extends Model
{
    protected $table = 'cascade_memos';

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
    Schema::connection('cascade_records')->create('cascade_marks', function ($t) {
        $t->id();
        $t->unsignedBigInteger('gift_id');
        $t->string('name')->default('mark');
        $t->softDeletes();
    });
    Schema::connection('cascade_records')->create('cascade_stamps', function ($t) {
        $t->id();
        $t->unsignedBigInteger('mark_id');
        $t->string('name')->default('stamp');
        $t->softDeletes();
    });
    Schema::connection('cascade_records')->create('cascade_memos', function ($t) {
        $t->id();
        $t->unsignedBigInteger('gift_id');
        $t->string('file')->nullable();
    });
    Schema::connection('cascade_records')->create('cascade_gift_products', function ($t) {
        $t->unsignedBigInteger('gift_id');
        $t->unsignedBigInteger('product_id');
        $t->integer('quantity')->default(1);
        $t->primary(['gift_id', 'product_id']);
    });
    Schema::connection('cascade_records')->create('cascade_extras', function ($t) {
        $t->id();
        $t->unsignedBigInteger('gift_id');
        $t->unsignedBigInteger('product_id');
        $t->softDeletes();
    });
    Schema::connection('cascade_records')->create('cascade_gift_sets', function ($t) {
        $t->unsignedBigInteger('gift_id');
        $t->unsignedBigInteger('product_id');
        $t->primary(['gift_id', 'product_id']);
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
    foreach (['cascade_extras', 'cascade_gift_sets', 'cascade_gift_products', 'cascade_memos', 'cascade_stamps', 'cascade_marks', 'cascade_tier_products', 'cascade_products', 'cascade_gifts', 'cascade_notes', 'cascade_steps', 'cascade_tiers', 'cascade_roots', 'brume_uploads'] as $table) {
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
                ->extra(function (Form $gift) use ($products) {
                    $gift->file('file');
                    $gift->repeater('marks', 'Marks', fn (Form $mark) => $mark->repeater('stamps', 'Stamps', fn (Form $stamp) => $stamp->text('name'))->relation('stamps'))->relation('marks');
                    $gift->repeater('memos', 'Memos', fn (Form $memo) => $memo->file('file'))->relation('memos');
                    $gift->relationPicker('bundle', 'Bundle', $products)->relation('bundle')->extra(fn (Form $item) => $item->number('quantity'));
                    $gift->relationPicker('sets', 'Sets', $products)->relation('sets');
                    $gift->relationPicker('extras', 'Extras', $products)->relation('extras')->associateBy('product_id')->restoreRemoved();
                });
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

it('removing a soft-deleted row trashes its soft gifts and keeps its notes attached', function () {
    ['root' => $root, 'tierA' => $tierA, 'tierB' => $tierB, 'steps' => $steps] = cascadeGraph();

    cascadeSave($root, ['tiers' => [cascadeRow($tierA, ['steps' => []]), cascadeRow($tierB)]]);

    expect(CascadeStep::withTrashed()->findOrFail($steps[0]->id)->trashed())->toBeTrue()
        ->and(CascadeGift::withTrashed()->where('step_id', $steps[0]->id)->get()->map(fn ($gift) => [$gift->trashed(), $gift->sold])->all())->toBe([[true, 7]])
        // A trashed row stays whole: its non-soft-deletable notes stay attached to it.
        ->and(CascadeNote::where('step_id', $steps[0]->id)->count())->toBe(1)
        ->and(CascadeTier::whereKey($tierA->id)->exists())->toBeTrue()
        ->and(DB::connection('cascade_records')->table('cascade_tier_products')->where('tier_id', $tierA->id)->value('quantity'))->toBe(3)
        ->and(CascadeGift::where('step_id', $steps[1]->id)->count())->toBe(1);
    Storage::disk('public')->assertExists(['files/step-0.txt', 'files/gift-0.txt', 'files/note-0.txt', 'files/tier-a.txt']);
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

/** Rebuilds steps, notes and gifts with `cascadeOnDelete` foreign keys, enforced on this connection. */
function cascadeForeignKeys(): void
{
    $schema = Schema::connection('cascade_records');
    $schema->enableForeignKeyConstraints();
    foreach (['cascade_gifts', 'cascade_notes', 'cascade_steps'] as $table) {
        $schema->drop($table);
    }
    $schema->create('cascade_steps', function ($t) {
        $t->id();
        $t->foreignId('tier_id')->constrained('cascade_tiers')->cascadeOnDelete();
        $t->string('name')->default('step');
        $t->string('file')->nullable();
        $t->softDeletes();
    });
    $schema->create('cascade_notes', function ($t) {
        $t->id();
        $t->foreignId('step_id')->constrained('cascade_steps')->cascadeOnDelete();
        $t->string('body')->default('note');
        $t->string('file')->nullable();
    });
    $schema->create('cascade_gifts', function ($t) {
        $t->id();
        $t->foreignId('step_id')->constrained('cascade_steps')->cascadeOnDelete();
        $t->unsignedBigInteger('product_id');
        $t->integer('sold')->default(0);
        $t->string('file')->nullable();
        $t->softDeletes();
    });
}

it('cleans the files of soft-deletable rows that a database cascade physically deleted', function () {
    cascadeForeignKeys();
    ['root' => $root, 'tierA' => $tierA, 'tierB' => $tierB, 'steps' => $steps] = cascadeGraph();

    cascadeSave($root, ['tiers' => [cascadeRow($tierB)]]);

    // The step and gift were trashed first, then the tier's delete cascaded them away for good.
    expect(CascadeTier::whereKey($tierA->id)->exists())->toBeFalse()
        ->and(CascadeStep::withTrashed()->whereKey($steps[0]->id)->exists())->toBeFalse()
        ->and(CascadeGift::withTrashed()->where('step_id', $steps[0]->id)->exists())->toBeFalse()
        ->and(CascadeStep::whereKey($steps[1]->id)->exists())->toBeTrue();
    Storage::disk('public')->assertMissing(['files/tier-a.txt', 'files/step-0.txt', 'files/gift-0.txt', 'files/note-0.txt']);
    Storage::disk('public')->assertExists(['files/owner.txt', 'files/picked.txt', 'files/step-1.txt', 'files/note-1.txt', 'files/gift-1.txt']);
});

/** Gives step one's gift two marks (one with a stamp), a memo and a bundled part. */
function cascadeGiftGraph(array $graph): array
{
    $gift = CascadeGift::where('step_id', $graph['steps'][0]->id)->firstOrFail();
    [$mark, $loose] = [$gift->marks()->create(), $gift->marks()->create(['name' => 'loose'])];
    $stamp = $mark->stamps()->create();
    $memo = $gift->memos()->create(['file' => 'files/memo.txt']);
    $gift->bundle()->attach($graph['part']->id, ['quantity' => 2]);
    Storage::disk('public')->put('files/memo.txt', 'memo');

    return compact('gift', 'mark', 'loose', 'stamp', 'memo');
}

function cascadeGiftValues(array $graph, ?array $gift): array
{
    return ['tiers' => [cascadeRow($graph['tierA'], ['steps' => [cascadeRow($graph['steps'][0], ['gifts' => $gift === null ? [] : [$gift]])]]), cascadeRow($graph['tierB'])]];
}

it('restoring a removed intermediary restores the rows its removal trashed and keeps its other rows and pivots', function () {
    $graph = cascadeGraph();
    ['gift' => $gift, 'mark' => $mark, 'loose' => $loose, 'stamp' => $stamp, 'memo' => $memo] = cascadeGiftGraph($graph);
    $pick = ['id' => (string) $graph['part']->id];

    // Trashed on its own first: the gift's removal and restoration must leave it alone.
    cascadeSave($graph['root'], cascadeGiftValues($graph, $pick + ['marks' => [cascadeRow($mark)]]));
    $looseTrashedAt = CascadeMark::withTrashed()->findOrFail($loose->id)->getRawOriginal('deleted_at');
    expect($looseTrashedAt)->not->toBeNull();

    $this->travel(1)->minutes();
    cascadeSave($graph['root'], cascadeGiftValues($graph, null));

    $pivot = fn () => DB::connection('cascade_records')->table('cascade_gift_products')->where('gift_id', $gift->id)->pluck('quantity', 'product_id')->all();
    expect($gift->fresh()->trashed())->toBeTrue()
        ->and(CascadeMark::withTrashed()->findOrFail($mark->id)->trashed())->toBeTrue()
        ->and(CascadeStamp::withTrashed()->findOrFail($stamp->id)->trashed())->toBeTrue()
        ->and(CascadeMark::withTrashed()->findOrFail($loose->id)->getRawOriginal('deleted_at'))->toBe($looseTrashedAt)
        ->and($memo->fresh())->not->toBeNull()
        ->and($pivot())->toBe([$graph['part']->id => 2]);
    Storage::disk('public')->assertExists(['files/gift-0.txt', 'files/memo.txt']);

    $this->travel(1)->minutes();
    cascadeSave($graph['root'], cascadeGiftValues($graph, $pick));

    expect($gift->fresh()->trashed())->toBeFalse()->and($gift->fresh()->sold)->toBe(7)
        ->and(CascadeMark::findOrFail($mark->id)->trashed())->toBeFalse()
        ->and(CascadeStamp::findOrFail($stamp->id)->trashed())->toBeFalse()
        ->and(CascadeMark::withTrashed()->findOrFail($loose->id)->trashed())->toBeTrue()
        ->and(CascadeMark::withTrashed()->findOrFail($loose->id)->getRawOriginal('deleted_at'))->toBe($looseTrashedAt)
        ->and(CascadeMemo::where('gift_id', $gift->id)->pluck('id')->all())->toBe([$memo->id])
        ->and($pivot())->toBe([$graph['part']->id => 2]);
    Storage::disk('public')->assertExists(['files/gift-0.txt', 'files/memo.txt']);
});

it('a row that was already trashed is not removed again, so its restore still finds its rows', function () {
    $graph = cascadeGraph();
    ['gift' => $gift, 'mark' => $mark] = cascadeGiftGraph($graph);

    cascadeSave($graph['root'], cascadeGiftValues($graph, null));
    $trashedAt = $gift->fresh()->getRawOriginal('deleted_at');
    $this->travel(1)->minutes();
    // The trashed gift is still an existing (withTrashed) row of its picker, and still not picked.
    cascadeSave($graph['root'], cascadeGiftValues($graph, null));
    expect($gift->fresh()->getRawOriginal('deleted_at'))->toBe($trashedAt);

    cascadeSave($graph['root'], cascadeGiftValues($graph, ['id' => (string) $graph['part']->id]));
    expect($gift->fresh()->trashed())->toBeFalse()->and(CascadeMark::findOrFail($mark->id)->trashed())->toBeFalse();
});

it('removing a hard-deleted row still purges the nested rows and pivots under its trashed rows', function () {
    $graph = cascadeGraph();
    ['gift' => $gift, 'mark' => $mark, 'stamp' => $stamp, 'memo' => $memo] = cascadeGiftGraph($graph);

    cascadeSave($graph['root'], ['tiers' => [cascadeRow($graph['tierB'])]]);

    expect(CascadeTier::whereKey($graph['tierA']->id)->exists())->toBeFalse()
        ->and($gift->fresh()->trashed())->toBeTrue()
        ->and(CascadeMark::withTrashed()->findOrFail($mark->id)->trashed())->toBeTrue()
        ->and(CascadeStamp::withTrashed()->findOrFail($stamp->id)->trashed())->toBeTrue()
        ->and(CascadeMemo::whereKey($memo->id)->exists())->toBeFalse()
        ->and(CascadeNote::where('step_id', $graph['steps'][0]->id)->exists())->toBeFalse()
        ->and(DB::connection('cascade_records')->table('cascade_gift_products')->where('gift_id', $gift->id)->exists())->toBeFalse()
        ->and(DB::connection('cascade_records')->table('cascade_tier_products')->where('tier_id', $graph['tierA']->id)->exists())->toBeFalse();
    Storage::disk('public')->assertMissing(['files/memo.txt', 'files/tier-a.txt', 'files/note-0.txt']);
    Storage::disk('public')->assertExists(['files/gift-0.txt', 'files/step-0.txt']);
});

/** Statements the synchronizer issues while removing every tier of a graph with $count populated tiers. */
function cascadeRemovalQueries(int $count): array
{
    $root = CascadeRoot::create();
    $part = CascadeProduct::create(['name' => 'part']);
    foreach (range(1, $count) as $index) {
        $tier = $root->tiers()->create();
        $tier->parts()->attach($part->id);
        foreach ([1, 2] as $step) {
            $step = $tier->steps()->create();
            $step->notes()->create();
            $gift = $step->gifts()->create(['product_id' => $part->id]);
            $gift->marks()->create()->stamps()->create();
            $gift->memos()->create();
            $gift->bundle()->attach($part->id);
        }
    }
    $connection = DB::connection('cascade_records');
    $connection->beginTransaction();
    try {
        $form = cascadeForm($root);
        $form->forRecord($root)->initializeForSave();
        $plan = (new RelationPlanner)->plan($form, $root, ['tiers' => []], $root);
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        (new RelationSynchronizer)->save($plan);
        $connection->disableQueryLog();
        $queries = array_column($connection->getQueryLog(), 'query');
    } finally {
        $connection->rollBack();
    }

    return $queries;
}

it('removes nested rows with one query per nested relation, however many rows are removed', function () {
    // Per-row writes (each model's own delete or trash, with its events) scale with the rows;
    // reads and pivot detaches must not.
    // The custom-class pivot (sets) detaches row by row on purpose; see the pivot events test.
    $batched = fn (array $queries) => array_values(array_filter($queries, fn ($sql) => ! str_contains($sql, 'cascade_gift_sets') && (str_starts_with($sql, 'select') || preg_match('/^delete from .*(tier|gift)_products/', $sql))));

    $one = $batched(cascadeRemovalQueries(1));
    $many = $batched(cascadeRemovalQueries(4));

    // tiers → parts, steps → notes, gifts → marks → stamps, memos, bundle.
    expect(count($many))->toBe(count($one));
    // One locked read per nested relation, one detach per pivot relation, for 4 tiers / 8 steps / 8 gifts.
    foreach (['steps', 'notes', 'gifts', 'marks', 'stamps', 'memos'] as $table) {
        expect(array_filter($many, fn ($sql) => str_starts_with($sql, 'select * from "cascade_'.$table.'"') || str_starts_with($sql, 'select * from `cascade_physical_cascade_'.$table.'`')))->toHaveCount(1);
    }
    expect(array_filter($many, fn ($sql) => str_starts_with($sql, 'delete')))->toHaveCount(2);
});

it('detaches custom pivot classes through their model, so pivot events fire', function () {
    $graph = cascadeGraph();
    ['gift' => $gift] = cascadeGiftGraph($graph);
    $gift->sets()->attach([$graph['part']->id, $graph['owner']->id]);
    CascadeSetPivot::$deleted = [];

    cascadeSave($graph['root'], ['tiers' => [cascadeRow($graph['tierB'])]]);

    expect(DB::connection('cascade_records')->table('cascade_gift_sets')->count())->toBe(0)
        ->and(collect(CascadeSetPivot::$deleted)->sort()->values()->all())->toBe(collect([[$gift->id, $graph['part']->id], [$gift->id, $graph['owner']->id]])->sort()->values()->all())
        // The plain pivot of the same gift is still detached in one statement.
        ->and(DB::connection('cascade_records')->table('cascade_gift_products')->count())->toBe(0);
});

it('restoring an intermediary keeps a nested restorable selection removed unless it is picked again', function (bool $repick) {
    $graph = cascadeGraph();
    ['gift' => $gift] = cascadeGiftGraph($graph);
    $extra = $gift->extras()->create(['product_id' => $graph['owner']->id]);
    $pick = ['id' => (string) $graph['part']->id];

    cascadeSave($graph['root'], cascadeGiftValues($graph, null));
    expect($extra->fresh()->trashed())->toBeTrue();
    $trashedAt = $extra->fresh()->getRawOriginal('deleted_at');

    $this->travel(1)->minutes();
    cascadeSave($graph['root'], cascadeGiftValues($graph, $pick + ['extras' => $repick ? [['id' => (string) $graph['owner']->id]] : []]));

    expect($gift->fresh()->trashed())->toBeFalse()
        ->and($extra->fresh()->trashed())->toBe(! $repick)
        ->and(CascadeExtra::withTrashed()->where('gift_id', $gift->id)->pluck('id')->all())->toBe([$extra->id]);
    if (! $repick) {
        expect($extra->fresh()->getRawOriginal('deleted_at'))->toBe($trashedAt);
    }
})->with(['not picked again' => false, 'picked again' => true]);
