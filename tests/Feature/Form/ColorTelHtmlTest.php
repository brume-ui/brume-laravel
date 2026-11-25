<?php

use Brume\BrumeManager;
use Brume\Form\ClientRules;
use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Form\FormValidator;
use Brume\Grid\Actions\Action;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Tests\Support\JsonSchema;
use Brume\Tests\Support\Models\CellItem;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class CtPost extends Model
{
    protected $table = 'rich_posts';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['blocks' => 'array'];

    public function sections()
    {
        return $this->hasMany(CtSection::class, 'post_id');
    }
}
class CtSection extends Model
{
    protected $table = 'rich_sections';

    protected $guarded = [];

    public $timestamps = false;
}

const COLOR_PATTERN = '^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$';
const COLOR_ALPHA_PATTERN = '^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$';
const TEL_PATTERN = '^(?:\\+886[- ]?|\\(?0)\\d{1,3}\\)?[- ]?\\d{2,4}[- ]?\\d{3,4}(?:(?:#| ?ext\\.? ?| ?分機 ?| ?轉 ?)\\d{1,6})?$';

function ctForm(?CtPost $record = null): Form
{
    return Form::make(CtPost::class)->id('posts')->forRecord($record)->submitTo('put', '/posts')->urls(fn ($r) => ['edit' => '/posts/'.$r->id]);
}

function ctSave(Form $form, ?Model $record, array $values): array
{
    return (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => $values]));
}

function ctErrors(Form $form, array $values): array
{
    try {
        FormValidator::validate($form, $values);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

function ctNodes(Form $form): array
{
    return $form->toSchema(Request::create('/'))['form']['nodes'];
}

it('serializes the default color and tel fields exactly', function () {
    $form = Form::make(CtPost::class)->submitTo('put', '/posts');
    $form->color('title', 'Tint');
    $form->tel('notes', 'Phone');
    $nodes = ctNodes($form);
    expect($nodes[0]['field'])->toBe([
        'rules' => [['rule' => 'pattern', 'pattern' => COLOR_PATTERN, 'message' => __('validation.regex', ['attribute' => 'Tint'])]],
        'type' => 'color', 'name' => 'title', 'label' => 'Tint',
    ])->and($nodes[1]['field'])->toBe([
        'rules' => [['rule' => 'pattern', 'pattern' => TEL_PATTERN, 'message' => __('validation.regex', ['attribute' => 'Phone'])]],
        'type' => 'tel', 'name' => 'notes', 'label' => 'Phone',
    ]);
});

it('serializes alpha and swatches and normalises each swatch', function () {
    $form = Form::make(CtPost::class)->submitTo('put', '/posts');
    $form->color('title')->alpha()->swatches(['#F00', '#00ff0080', '#ABCD']);
    $field = ctNodes($form)[0]['field'];
    expect($field['alpha'])->toBeTrue()
        ->and($field['swatches'])->toBe(['#ff0000', '#00ff0080', '#aabbccdd'])
        ->and($field['rules'][0]['pattern'])->toBe(COLOR_ALPHA_PATTERN);
    $form = Form::make(CtPost::class)->submitTo('put', '/posts');
    $form->color('title')->alpha()->alpha(false);
    expect(ctNodes($form)[0]['field'])->not->toHaveKey('alpha');
});

it('rejects invalid swatches', function (array $swatches) {
    expect(fn () => Form::make(CtPost::class)->color('title')->swatches($swatches))->toThrow(InvalidArgumentException::class);
})->with([
    'not a colour' => [['red']],
    'bad length' => [['#12345']],
    'non-string' => [[123]],
    'too many' => [array_map(fn ($n) => sprintf('#%06x', $n), range(1, 25))],
]);

it('accepts exactly 24 swatches', function () {
    $field = Form::make(CtPost::class)->color('title')->swatches(array_map(fn ($n) => sprintf('#%06x', $n), range(1, 24)));
    expect($field->settings()['swatches'])->toHaveCount(24);
});

it('refuses alpha swatches on a field without alpha when rendering', function () {
    $form = Form::make(CtPost::class)->submitTo('put', '/posts');
    $form->color('title')->swatches(['#00ff0080']);
    expect(fn () => ctNodes($form))->toThrow(InvalidArgumentException::class);
});

it('emits schemas the vendored schema accepts', function () {
    $form = Form::make(CtPost::class)->id('posts')->name('Post')->submitTo('post', '/posts');
    $form->color('title')->swatches(['#fff'])->default('#ABC');
    $form->color('notes')->alpha()->required();
    $form->tel('blocks_phone')->pattern('^\d{4}$', 'Four digits')->help('h');
    $form->html('<p>Hello <strong>there</strong></p>', 'Note');
    $form->html(fn ($record) => new HtmlString('<p>x</p>'));
    $form->row(function ($r) {
        $r->color('a')->width(6);
        $r->tel('b')->width(6);
    });
    $form->divider();
    $form->html('<p>after</p>');
    $envelope = fn () => ['schemaVersion' => BrumeManager::SCHEMA_VERSION, 'page' => $form->toSchema(Request::create('/'))];
    expect(JsonSchema::errors($envelope(), 'PageEnvelope'))->toBe([]);
    $form->forRecord((new CtPost)->forceFill(['id' => 9, 'title' => '#112233']));
    expect(JsonSchema::errors($envelope(), 'PageEnvelope'))->toBe([]);
});

it('validates colour input on the server and agrees with the published pattern', function (string $input, bool $valid) {
    $form = Form::make(CtPost::class);
    $form->color('title');
    expect(ctErrors($form, ['title' => $input]) === [])->toBe($valid);
    expect((bool) preg_match('~'.COLOR_PATTERN.'~uD', $input))->toBe($valid);
    expect((bool) preg_match('/'.str_replace('/', '\\/', COLOR_PATTERN).'/uD', $input))->toBe($valid);
})->with([
    ['#ABC', true], ['#abc', true], ['#AABBCC', true], ['abc', false], ['red', false], ['#12345', false],
    ['#GGGGGG', false], ['#aabbccdd', false], ['#abcd', false], ["#abc\n", false], [' #abc', false],
]);

it('validates alpha colour input', function (string $input, bool $valid) {
    $form = Form::make(CtPost::class);
    $form->color('title')->alpha();
    expect(ctErrors($form, ['title' => $input]) === [])->toBe($valid);
})->with([['#abcd', true], ['#aabbccdd', true], ['#aabbcc', true], ['#abc', true], ['#12345', false], ['#aabbccd', false], ['#aabbccddee', false], ['red', false]]);

it('uses the Laravel regex message in every locale', function (string $locale) {
    app()->setLocale($locale);
    foreach ([fn (Form $f) => $f->color('value', 'Value'), fn (Form $f) => $f->tel('value', 'Value')] as $make) {
        $form = Form::make(CtPost::class);
        $field = $make($form);
        $errors = ctErrors($form, ['value' => 'nope']);
        $client = collect(ClientRules::for($field, 'value', null))->firstWhere('rule', 'pattern');
        expect($errors['value'][0])->toBe($client['message'])->and($client['message'])->not->toBe('validation.regex');
    }
})->with(['en', 'zh_TW', 'zh_CN']);

it('stores the canonical lowercase long colour', function (string $input, string $stored, bool $alpha) {
    $post = CtPost::create();
    $form = ctForm($post);
    $field = $form->color('title');
    if ($alpha) {
        $field->alpha();
    }
    ctSave($form, $post, ['title' => $input]);
    expect($post->fresh()->title)->toBe($stored);
})->with([
    ['#ABC', '#aabbcc', false], ['#AABBCC', '#aabbcc', false], ['#aabbcc', '#aabbcc', false],
    ['#ABCD', '#aabbccdd', true], ['#AABBCCDD', '#aabbccdd', true], ['#abc', '#aabbcc', true],
]);

it('passes the canonical colour to saveUsing and rejects bad colours on save', function () {
    $post = CtPost::create();
    $seen = null;
    $form = ctForm($post);
    $form->color('title')->saveUsing(function ($record, $value) use (&$seen) {
        $seen = $value;
    });
    ctSave($form, $post, ['title' => '#FA0']);
    expect($seen)->toBe('#ffaa00');
    foreach (['red', '#12345', '#aabbccdd', 'abc'] as $bad) {
        $form = ctForm($post);
        $form->color('title');
        expect(fn () => ctSave($form, $post, ['title' => $bad]))->toThrow(ValidationException::class);
    }
});

it('stores null for a cleared colour', function () {
    $post = CtPost::create(['title' => '#ffffff']);
    $form = ctForm($post);
    $form->color('title');
    ctSave($form, $post, ['title' => null]);
    expect($post->fresh()->title)->toBeNull();
});

it('accepts and rejects tel numbers as the pattern says', function (string $input, bool $valid) {
    $form = Form::make(CtPost::class);
    $form->tel('notes');
    expect(ctErrors($form, ['notes' => $input]) === [])->toBe($valid);
    expect((bool) preg_match('/'.str_replace('/', '\\/', TEL_PATTERN).'/uD', $input))->toBe($valid);
})->with([
    ['02-2345-6789#123', true], ['(04)2345678', true], ['0912-345-678', true], ['+886 2 2345 6789', true],
    ['+886-2-2345-6789', true], ['0223456789', true], ['02-2345-6789 ext. 12', true], ['02-2345-6789 分機 12', true], ['02-2345-6789轉12', true],
    ['abc', false], [str_repeat('1', 60), false], ['  02-2345-6789  ', false], ["02-2345-6789\n", false],
    ['02-2345-6789#', false], ['02-2345-6789#1234567', false], ['886223456789', false],
]);

it('lets an explicit tel pattern replace the default', function () {
    $form = Form::make(CtPost::class);
    $field = $form->tel('notes')->pattern('^\d{4}$');
    expect(ctErrors($form, ['notes' => '1234']))->toBe([])
        ->and(ctErrors($form, ['notes' => '02-2345-6789']))->toHaveKey('notes')
        ->and(ClientRules::for($field, 'notes', null)[0]['pattern'])->toBe('^\d{4}$');
});

it('trims a tel value when it is stored', function () {
    $post = CtPost::create();
    $form = ctForm($post);
    $form->tel('notes')->pattern('^\s*\d+\s*$');
    ctSave($form, $post, ['notes' => ' 0223456789 ']);
    expect($post->fresh()->notes)->toBe('0223456789');
    $form = ctForm($post);
    $form->tel('notes');
    ctSave($form, $post, ['notes' => null]);
    expect($post->fresh()->notes)->toBeNull();
});

it('stores a tel exactly as typed', function () {
    $post = CtPost::create();
    $form = ctForm($post);
    $form->tel('notes');
    ctSave($form, $post, ['notes' => '+886 2 2345 6789']);
    expect($post->fresh()->notes)->toBe('+886 2 2345 6789');
});

it('saves colour and tel inside json repeater rows', function () {
    $post = CtPost::create();
    $form = ctForm($post);
    $form->repeater('blocks', 'Blocks', function (Form $row) {
        $row->color('tint');
        $row->tel('phone');
    });
    ctSave($form, $post, ['blocks' => [['_brume' => ['key' => 'a'], 'tint' => '#ABC', 'phone' => '0912-345-678']]]);
    expect($post->fresh()->blocks[0])->toMatchArray(['tint' => '#aabbcc', 'phone' => '0912-345-678']);
    $form = ctForm($post);
    $form->repeater('blocks', 'Blocks', fn (Form $row) => $row->color('tint'));
    expect(fn () => ctSave($form, $post, ['blocks' => [['_brume' => ['key' => 'a'], 'tint' => 'red']]]))->toThrow(ValidationException::class);
});

it('saves colour and tel inside relation rows', function (string $type, string $input, string $stored) {
    $post = CtPost::create();
    $section = $post->sections()->create(['body' => 'old']);
    $form = ctForm($post);
    $form->repeater('sections', 'Sections', fn (Form $row) => $row->$type('body'))->relation('sections');
    ctSave($form, $post, ['sections' => [
        ['_brume' => ['key' => 'record:'.$section->id, 'id' => (string) $section->id], 'body' => $input],
        ['_brume' => ['key' => 'new'], 'body' => $input],
    ]]);
    expect($post->sections()->orderBy('id')->pluck('body')->all())->toBe([$stored, $stored]);
})->with([['color', '#ABC', '#aabbcc'], ['tel', '0912-345-678', '0912-345-678']]);

it('allows colour and tel in flat extras and rejects html there', function () {
    $form = Form::make(CtPost::class);
    $form->color('a');
    $form->tel('b');
    expect($form->plainFields())->toHaveCount(2);
    $form = Form::make(CtPost::class);
    $form->html('<p>x</p>');
    expect(fn () => $form->plainFields())->toThrow(InvalidArgumentException::class);
});

it('accepts colour and tel in an action form', function () {
    $this->actingAs(brumeUser(), 'brume');
    $item = CellItem::create(['shop_id' => 1, 'customer' => 'Ada']);
    $seen = (object) ['input' => null];
    Route::middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeGrid('ct-items', function () use ($seen) {
        $grid = Grid::make(CellItem::class);
        $grid->column('customer');
        $grid->rowActions(Action::make('paint', 'Paint')->form(function (Form $form) {
            $form->color('tint')->required();
            $form->tel('phone');
        })->handle(function ($models, $request, array $input) use ($seen) {
            $seen->input = $input;
        }));

        return $grid;
    }));
    $page = $this->get('/ct-items')->assertOk()->viewData('page')['props']['envelope']['page'];
    expect(array_column($page['actions']['row'][0]['form']['fields'], 'type'))->toContain('color', 'tel');
    $body = ['target' => 'row', 'ids' => [$item->id], 'input' => ['tint' => 'red', 'phone' => '0912-345-678']];
    $this->postJson('/ct-items/_brume/actions/paint', $body)->assertUnprocessable()->assertJsonValidationErrors('tint');
    $body['input']['tint'] = '#F0A';
    $this->postJson('/ct-items/_brume/actions/paint', $body)->assertOk();
    expect($seen->input)->toBe(['tint' => '#ff00aa', 'phone' => '0912-345-678']);
});

it('emits an html block for a string', function () {
    $form = Form::make(CtPost::class)->submitTo('put', '/posts');
    $form->html('<p>Hi <b>you</b></p>');
    $form->html('<p>Labelled</p>', 'Heads up');
    expect(ctNodes($form))->toBe([
        ['type' => 'html', 'html' => '<p>Hi <b>you</b></p>'],
        ['type' => 'html', 'html' => '<p>Labelled</p>', 'label' => 'Heads up'],
    ]);
});

it('passes the string through unsanitised on the server', function () {
    $form = Form::make(CtPost::class)->submitTo('put', '/posts');
    $form->html('<p onclick="x">a</p><script>1</script>');
    expect(ctNodes($form)[0]['html'])->toBe('<p onclick="x">a</p><script>1</script>');
});

it('converts an Htmlable with toHtml', function () {
    $form = Form::make(CtPost::class)->submitTo('put', '/posts');
    $form->html(new class implements Htmlable
    {
        public function toHtml(): string
        {
            return '<em>made</em>';
        }
    });
    expect(ctNodes($form)[0])->toBe(['type' => 'html', 'html' => '<em>made</em>']);
});

it('resolves a closure with a null record on create and the record on edit', function () {
    $calls = [];
    $make = function (?CtPost $record) use (&$calls) {
        $form = ctForm($record);
        $form->html(function (?Model $r) use (&$calls) {
            $calls[] = $r?->id;

            return $r === null ? '<p>new</p>' : new HtmlString('<p>edit '.$r->id.'</p>');
        });

        return ctNodes($form);
    };
    expect($make(null)[0]['html'])->toBe('<p>new</p>');
    $post = CtPost::create();
    expect($make($post)[0]['html'])->toBe('<p>edit '.$post->id.'</p>')->and($calls)->toBe([null, $post->id]);
});

it('places html wherever a divider is allowed and throws where a divider does', function () {
    $form = Form::make(CtPost::class)->submitTo('put', '/posts')->tabs();
    $form->section('One', function (Form $f) {
        $f->html('<p>a</p>');
        $f->text('title');
        $f->block('B', fn (Form $b) => $b->html('<p>b</p>'));
        $f->columns(fn ($c) => $c->column(12, fn ($col) => $col->html('<p>c</p>')));
        $f->embeds('meta', 'Meta', fn ($e) => $e->html('<p>d</p>')->text('x'));
    });
    $nodes = ctNodes($form);
    expect($nodes[0]['children'][0])->toBe(['type' => 'html', 'html' => '<p>a</p>'])
        ->and($nodes[0]['children'][2]['children'][0]['type'])->toBe('html')
        ->and($nodes[0]['children'][3]['columns'][0]['children'][0]['type'])->toBe('html');
    expect(fn () => Form::make(CtPost::class)->row(fn ($r) => $r->html('<p>x</p>')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Form::make(CtPost::class)->columns(fn ($c) => $c->html('<p>x</p>')))->toThrow(InvalidArgumentException::class);
});

it('keeps html out of validation, fields and saving', function () {
    $post = CtPost::create();
    $form = ctForm($post);
    $form->html('<p>note</p>');
    $form->text('title')->required();
    $form->repeater('blocks', 'Blocks', function (Form $row) {
        $row->html('<p>row note</p>');
        $row->text('label');
    });
    expect(array_keys($form->fields()))->toBe(['title', 'blocks'])
        ->and(ctErrors($form, ['title' => 'ok']))->toBe([]);
    ctSave($form, $post, ['title' => 'ok', 'blocks' => [['_brume' => ['key' => 'a'], 'label' => 'x']]]);
    expect($post->fresh()->blocks)->toHaveCount(1)->and($post->fresh()->blocks[0])->not->toHaveKey('html');
    $schema = $form->toSchema(Request::create('/'));
    expect($schema['values'])->not->toHaveKey('html')->and($schema['form']['nodes'][2]['field']['nodes'][0])->toBe(['type' => 'html', 'html' => '<p>row note</p>']);
});
