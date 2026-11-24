<?php

use Brume\Form\ClientRules;
use Brume\Form\Conditions;
use Brume\Form\Fields\RichText;
use Brume\Form\Form;
use Brume\Form\FormSaver;
use Brume\Form\FormValidator;
use Brume\Grid\Actions\Action;
use Brume\Grid\Grid;
use Brume\Html\HtmlSanitizer;
use Brume\Tests\Support\JsonSchema;
use Brume\Tests\Support\Models\CellItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

class RichPost extends Model
{
    protected $table = 'rich_posts';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['blocks' => 'array'];

    public function sections()
    {
        return $this->hasMany(RichSection::class, 'post_id');
    }
}
class RichSection extends Model
{
    protected $table = 'rich_sections';

    protected $guarded = [];

    public $timestamps = false;
}

const RICH_HOSTILE = '<p>ok</p><img src="x" onerror="alert(1)"><script>alert(2)</script><p onclick="alert(3)">keep</p>';

function richForm(?RichPost $record = null): Form
{
    return Form::make(RichPost::class)->id('posts')->forRecord($record)->submitTo('put', '/posts')->urls(fn ($r) => ['edit' => '/posts/'.$r->id]);
}

function richSave(Form $form, ?Model $record, array $values): array
{
    return (new FormSaver)->save($form, $record, Request::create('/', 'PUT', ['values' => $values]));
}

function richClean(?string $html): ?string
{
    return app(HtmlSanitizer::class)->clean($html);
}

function richPage(Form $form): array
{
    return json_decode(json_encode($form->toSchema(Request::create('/'))), true);
}

function expectRichSafe(?string $html): void
{
    expect($html)->toBeString()->not->toContain('onerror')->not->toContain('onclick')->not->toContain('<script')
        ->toContain('<p>ok</p>')->toContain('keep')->toContain('<img src="x"');
}

// Schema

it('serializes the default editor schema exactly', function () {
    $field = Form::make(RichPost::class)->editor('body', 'Body');
    expect($field)->toBeInstanceOf(RichText::class)
        ->and($field->toSchema(null))->toBe(['type' => 'richText', 'name' => 'body', 'label' => 'Body', 'images' => ['accept' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 'maxSize' => 10240 * 1024]]);
});

it('serializes height, content css and images exactly', function (Closure $configure, array $expected) {
    $field = Form::make(RichPost::class)->editor('body', 'Body');
    $configure($field);
    expect($field->toSchema(null))->toBe(['type' => 'richText', 'name' => 'body', 'label' => 'Body'] + $expected);
})->with([
    'height' => [fn (RichText $f) => $f->height(400)->withoutImages(), ['height' => 400]],
    'height bounds' => [fn (RichText $f) => $f->height(120)->height(2000)->withoutImages(), ['height' => 2000]],
    'one stylesheet' => [fn (RichText $f) => $f->contentCss('/css/a.css')->withoutImages(), ['contentCss' => ['/css/a.css']]],
    'stylesheets, blanks dropped' => [fn (RichText $f) => $f->contentCss(['/css/a.css', '', '  ', 'https://cdn.example.com/b.css'])->withoutImages(), ['contentCss' => ['/css/a.css', 'https://cdn.example.com/b.css']]],
    'only blank stylesheets' => [fn (RichText $f) => $f->contentCss(['', ' '])->withoutImages(), []],
    'without images' => [fn (RichText $f) => $f->withoutImages(), []],
    'custom accept and size' => [fn (RichText $f) => $f->accept(['image/png', 'image/webp'])->maxSize(512), ['images' => ['accept' => ['image/png', 'image/webp'], 'maxSize' => 512 * 1024]]],
]);

it('follows the configured upload size and disk by default', function () {
    config(['brume.uploads.max_size' => 2048, 'brume.uploads.disk' => 'media']);
    $field = Form::make(RichPost::class)->editor('body');
    expect($field->toSchema(null)['images']['maxSize'])->toBe(2048 * 1024)
        ->and($field->maxBytes())->toBe(2048 * 1024)
        ->and($field->diskName())->toBe('media');
});

it('exposes storage settings for the image endpoint', function () {
    $field = Form::make(RichPost::class)->editor('body');
    expect($field->diskName())->toBe('public')
        ->and($field->directoryFor('posts'))->toBe('brume/posts/editor')
        ->and($field->acceptedMimes())->toBe(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
        ->and($field->maxBytes())->toBe(10240 * 1024)
        ->and($field->imagesEnabled())->toBeTrue();
    $field->disk('s3')->directory('posts/images')->accept(['image/png'])->maxSize(100);
    expect($field->diskName())->toBe('s3')
        ->and($field->directoryFor('posts'))->toBe('posts/images')
        ->and($field->acceptedMimes())->toBe(['image/png'])
        ->and($field->maxBytes())->toBe(100 * 1024)
        ->and($field->toSchema(null))->not->toHaveKeys(['disk', 'directory']);
    expect($field->withoutImages()->imagesEnabled())->toBeFalse();
});

it('rejects invalid editor settings', function (Closure $configure, string $message) {
    expect(fn () => $configure(Form::make(RichPost::class)->editor('body')))->toThrow(InvalidArgumentException::class, $message);
})->with([
    [fn (RichText $f) => $f->height(119), 'Editor height must be between 120 and 2000.'],
    [fn (RichText $f) => $f->height(50), 'Editor height must be between 120 and 2000.'],
    [fn (RichText $f) => $f->height(3000), 'Editor height must be between 120 and 2000.'],
    [fn (RichText $f) => $f->accept([]), 'Editor image types cannot be empty; use withoutImages() to disable images.'],
    [fn (RichText $f) => $f->accept(['image/png', 'image/svg+xml']), 'Editor images must be image/jpeg, image/png, image/gif or image/webp; SVG is never accepted.'],
    [fn (RichText $f) => $f->accept(['IMAGE/SVG+XML']), 'Editor images must be image/jpeg, image/png, image/gif or image/webp; SVG is never accepted.'],
    [fn (RichText $f) => $f->accept(['application/pdf']), 'Editor images must be image/jpeg, image/png, image/gif or image/webp; SVG is never accepted.'],
    [fn (RichText $f) => $f->accept(['image/avif']), 'Editor images must be image/jpeg, image/png, image/gif or image/webp; SVG is never accepted.'],
    [fn (RichText $f) => $f->directory('../x'), 'Editor image directory must be a relative path without "." or ".." segments.'],
    [fn (RichText $f) => $f->directory('/abs'), 'Editor image directory must be a relative path without "." or ".." segments.'],
    [fn (RichText $f) => $f->directory(''), 'Editor image directory must be a relative path without "." or ".." segments.'],
    [fn (RichText $f) => $f->directory('a\\b'), 'Editor image directory must be a relative path without "." or ".." segments.'],
    [fn (RichText $f) => $f->accept(['']), 'Editor images must be image/jpeg, image/png, image/gif or image/webp; SVG is never accepted.'],
    [fn (RichText $f) => $f->maxSize(0), 'Max upload size must be positive.'],
    [fn (RichText $f) => $f->attributes(['a' => 'b']), 'Rich text fields store one HTML string and cannot map attributes.'],
]);

it('emits client rules for required and lengths and validates against the schema', function () {
    $form = richForm()->name('Post');
    $field = $form->editor('body', 'Body')->required()->minLength(10)->maxLength(500)->height(300)->contentCss('/css/a.css');
    expect(array_column(ClientRules::for($field, 'body'), 'rule'))->toBe(['required', 'minLength', 'maxLength'])
        ->and(JsonSchema::errors(['schemaVersion' => '0.4.0', 'page' => $form->toSchema(Request::create('/'))], 'PageEnvelope'))->toBe([]);
});

it('emits field definitions the vendored schema accepts, and the schema rejects bad ones', function () {
    foreach ([fn (RichText $f) => $f, fn (RichText $f) => $f->withoutImages()->height(120)->contentCss('/a.css'), fn (RichText $f) => $f->required()->readOnly()->default('<p>x</p>')] as $configure) {
        expect(JsonSchema::errors($configure(Form::make(RichPost::class)->editor('body'))->toSchema(null), 'FieldDef'))->toBe([]);
    }
    expect(JsonSchema::errors(['type' => 'richText', 'name' => 'body', 'label' => 'Body', 'height' => 50], 'FieldDef'))->not->toBe([])
        ->and(JsonSchema::errors(['type' => 'richText', 'name' => 'body', 'label' => 'Body', 'images' => ['accept' => ['image/png'], 'maxSize' => 0]], 'FieldDef'))->not->toBe([]);
});

it('is a long field', function () {
    expect(Form::make(RichPost::class)->editor('body')->isLong())->toBeTrue();
});

// Write

it('stores cleaned html and keeps legitimate siblings', function () {
    $post = RichPost::create();
    $form = richForm($post);
    $form->editor('body');
    richSave($form, $post, ['body' => RICH_HOSTILE]);
    $stored = $post->fresh()->body;
    expectRichSafe($stored);
    expect($stored)->toBe(richClean(RICH_HOSTILE));
});

it('stores null for an empty document', function (?string $input) {
    $post = RichPost::create(['body' => '<p>old</p>']);
    $form = richForm($post);
    $form->editor('body');
    richSave($form, $post, ['body' => $input]);
    expect($post->fresh()->body)->toBeNull();
})->with(['<p><br></p>', '<p>&nbsp;</p>', "  \n ", '', null, '<script>alert(1)</script>']);

it('passes the cleaned html to a saveUsing writer', function () {
    $post = RichPost::create();
    $received = 'unset';
    $form = richForm($post);
    $form->editor('body')->saveUsing(function (Model $record, mixed $value) use (&$received) {
        $received = $value;
        $record->notes = $value;
    });
    richSave($form, $post, ['body' => RICH_HOSTILE]);
    expect($received)->toBe(richClean(RICH_HOSTILE))->and($post->fresh()->notes)->toBe(richClean(RICH_HOSTILE));
    richSave($form, $post, ['body' => '<p><br></p>']);
    expect($received)->toBeNull();
});

it('sees the raw submitted value in the saving hook and stores the cleaned one', function () {
    $post = RichPost::create();
    $seen = null;
    $form = richForm($post);
    $form->editor('body');
    $form->saving(function ($record, &$values) use (&$seen) {
        $seen = $values['body'];
    });
    richSave($form, $post, ['body' => RICH_HOSTILE]);
    expect($seen)->toBe(RICH_HOSTILE)->and($post->fresh()->body)->toBe(richClean(RICH_HOSTILE));
});

it('cleans rich text inside json repeater rows on save', function () {
    $post = RichPost::create();
    $form = richForm($post);
    $form->repeater('blocks', 'Blocks', fn (Form $row) => $row->editor('body'));
    richSave($form, $post, ['blocks' => [['_brume' => ['key' => 'a'], 'body' => RICH_HOSTILE], ['_brume' => ['key' => 'b'], 'body' => '<p>&nbsp;</p>']]]);
    $blocks = $post->fresh()->blocks;
    expectRichSafe($blocks[0]['body']);
    expect($blocks[0]['body'])->toBe(richClean(RICH_HOSTILE))->and($blocks[1]['body'])->toBeNull();
});

it('cleans rich text inside relation rows on save', function () {
    $post = RichPost::create();
    $section = $post->sections()->create(['body' => '<p>old</p>']);
    $form = richForm($post);
    $form->repeater('sections', 'Sections', fn (Form $row) => $row->editor('body'))->relation('sections');
    richSave($form, $post, ['sections' => [
        ['_brume' => ['key' => 'record:'.$section->id, 'id' => (string) $section->id], 'body' => RICH_HOSTILE],
        ['_brume' => ['key' => 'new'], 'body' => '<p>second</p><script>x()</script>'],
    ]]);
    $rows = $post->sections()->orderBy('id')->pluck('body')->all();
    expectRichSafe($rows[0]);
    expect($rows)->toBe([richClean(RICH_HOSTILE), '<p>second</p>']);
});

it('normalises legacy content when an edited record is saved without touching the field', function () {
    $legacy = '<p style="color: red !important">Legacy&nbsp;text</p><img src="x" onerror="alert(1)">';
    $post = RichPost::create(['body' => $legacy, 'title' => 'Old']);
    $form = richForm($post);
    $form->text('title');
    $form->editor('body');
    $page = richPage($form);
    $sent = $page['values']['body'];
    expect($sent)->toBe(richClean($legacy))->not->toBe($legacy);
    // The browser posts back exactly what it received; the record is saved without error.
    $again = richForm($post);
    $again->text('title');
    $again->editor('body');
    richSave($again, $post, ['title' => 'New', 'body' => $sent]);
    expect($post->fresh()->body)->toBe(richClean($legacy))->and($post->fresh()->title)->toBe('New');
    // Cleaning is idempotent, so a second round trip changes nothing.
    expect(richClean($post->fresh()->body))->toBe($post->fresh()->body);
});

it('leaves the stored value alone when the field is omitted from the submission', function () {
    $post = RichPost::create(['body' => RICH_HOSTILE, 'title' => 'Old']);
    $form = richForm($post);
    $form->text('title');
    $form->editor('body');
    richSave($form, $post, ['title' => 'New']);
    expect($post->fresh()->body)->toBe(RICH_HOSTILE);
});

// Validation

it('fails required for empty documents and passes for media-only documents', function (?string $input, bool $passes) {
    $form = richForm();
    $form->editor('body', 'Body')->required();
    if ($passes) {
        expect(FormValidator::validate($form, ['body' => $input]))->toBe(['body' => $input]);

        return;
    }
    try {
        FormValidator::validate($form, ['body' => $input]);
        test()->fail('Expected validation failure');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['body' => [__('validation.required', ['attribute' => 'Body'])]]);
    }
})->with([
    ['<p><br></p>', false], ['<p>&nbsp;</p>', false], ["<p>\u{00A0}</p>", false], ["  \n\t ", false], ['', false], [null, false],
    ['<script>alert(1)</script>', false],
    ['<p><img src="/a.png"></p>', true], ['<p>x</p>', true],
    ['<iframe src="https://www.youtube.com/embed/abcdefghijk"></iframe>', true],
]);

it('uses the field custom required message for empty documents', function () {
    $form = richForm();
    $form->editor('body', 'Body')->required()->messages(['required' => '請輸入內容']);
    expect(fn () => FormValidator::validate($form, ['body' => '<p><br></p>']))->toThrow(ValidationException::class, '請輸入內容');
});

it('rejects a missing required document on save', function () {
    $post = RichPost::create(['body' => '<p>old</p>']);
    $form = richForm($post);
    $form->editor('body')->required();
    expect(fn () => richSave($form, $post, ['body' => '<p><br></p>']))->toThrow(ValidationException::class);
    expect($post->fresh()->body)->toBe('<p>old</p>');
});

it('rejects non-string values as validation errors', function (mixed $input) {
    $form = richForm();
    $form->editor('body', 'Body');
    try {
        FormValidator::validate($form, ['body' => $input]);
        test()->fail('Expected validation failure');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['body']);
    }
})->with([[['<p>x</p>']], [['a' => '<p>x</p>']], [12], [true]]);

it('rejects an array payload on save without a type error', function () {
    $post = RichPost::create(['body' => '<p>old</p>']);
    $form = richForm($post);
    $form->editor('body');
    expect(fn () => richSave($form, $post, ['body' => ['<p>x</p>']]))->toThrow(ValidationException::class);
    expect($post->fresh()->body)->toBe('<p>old</p>');
});

it('rejects a json object payload on save without a type error', function () {
    $post = RichPost::create(['body' => '<p>old</p>']);
    $form = richForm($post);
    $form->editor('body');
    $request = Request::create('/', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['values' => ['body' => ['html' => '<p>x</p>']]]));
    expect(fn () => (new FormSaver)->save($form, $post, $request))->toThrow(ValidationException::class);
    expect($post->fresh()->body)->toBe('<p>old</p>');
});

it('measures min and max length on the submitted html', function () {
    $form = richForm();
    $form->editor('body', 'Body')->maxLength(10);
    expect(FormValidator::validate($form, ['body' => '<p>12</p>']))->toBe(['body' => '<p>12</p>'])
        ->and(fn () => FormValidator::validate($form, ['body' => '<p>1234</p>']))->toThrow(ValidationException::class);
    $form = richForm();
    $form->editor('body', 'Body')->minLength(10);
    expect(fn () => FormValidator::validate($form, ['body' => '<p>1</p>']))->toThrow(ValidationException::class);
});

it('treats rich text as a string condition operand', function () {
    $form = richForm();
    $form->editor('body', 'Body');
    $form->text('title')->required()->showWhen(Conditions::eq(Conditions::root('body'), '<p>x</p>'));
    expect(fn () => FormValidator::validate($form, ['body' => '<p>x</p>']))->toThrow(ValidationException::class)
        ->and(FormValidator::validate($form, ['body' => '<p>y</p>']))->toBe(['body' => '<p>y</p>'])
        ->and(Conditions::canonical(['type' => 'richText'], ['<p>x</p>'])[0])->toBeFalse()
        ->and(Conditions::canonical(['type' => 'richText'], '<p>x</p>')[0])->toBeTrue();
});

// Read

it('sends cleaned html to the browser for a root field', function () {
    $post = RichPost::create(['body' => RICH_HOSTILE]);
    $form = richForm($post);
    $form->editor('body');
    $page = richPage($form);
    expectRichSafe($page['values']['body']);
    expect(json_encode($page))->not->toContain('onerror');
});

it('sends cleaned html for json repeater rows', function () {
    $post = RichPost::create(['blocks' => [['body' => RICH_HOSTILE]]]);
    $form = richForm($post);
    $form->repeater('blocks', 'Blocks', fn (Form $row) => $row->editor('body'));
    $page = richPage($form);
    expectRichSafe($page['values']['blocks'][0]['body']);
    expect(json_encode($page))->not->toContain('onerror');
});

it('sends cleaned html for relation rows', function () {
    $post = RichPost::create();
    $post->sections()->create(['body' => RICH_HOSTILE]);
    $form = richForm($post);
    $form->repeater('sections', 'Sections', fn (Form $row) => $row->editor('body'))->relation('sections');
    $page = richPage($form);
    expectRichSafe($page['values']['sections'][0]['body']);
    expect(json_encode($page))->not->toContain('onerror');
});

it('cleans value() reader output and defaults', function () {
    $post = RichPost::create();
    $form = richForm($post);
    $form->editor('body')->value(fn () => RICH_HOSTILE);
    $form->editor('notes')->default(RICH_HOSTILE);
    $page = richPage($form);
    expectRichSafe($page['values']['body']);
    expect($page['values']['notes'])->toBeNull();
    $create = richForm();
    $create->editor('notes')->default(RICH_HOSTILE);
    $page = richPage($create);
    expectRichSafe($page['values']['notes']);
    expectRichSafe($page['form']['nodes'][0]['field']['defaultValue']);
    expect(json_encode($page))->not->toContain('onerror');
});

it('sends null for an empty or non-string stored value', function (mixed $stored) {
    $post = RichPost::create(['body' => $stored]);
    $form = richForm($post);
    $form->editor('body');
    expect(richPage($form)['values']['body'])->toBeNull();
})->with(['<p><br></p>', null, '']);

it('keeps a read-only editor cleaned on read and ignores submitted input', function () {
    $post = RichPost::create(['body' => RICH_HOSTILE, 'title' => 'Old']);
    $form = richForm($post);
    $form->text('title');
    $form->editor('body')->readOnly();
    $page = richPage($form);
    expectRichSafe($page['values']['body']);
    expect($page['form']['nodes'][1]['field']['readOnly'])->toBeTrue()->and($page['form']['nodes'][1]['field'])->not->toHaveKey('rules');
    richSave($form, $post, ['title' => 'New', 'body' => '<p>forged</p>']);
    expect($post->fresh()->body)->toBe(RICH_HOSTILE)->and($post->fresh()->title)->toBe('New');
});

// Where rich text is not allowed

it('rejects editor in action forms, quick create and upload extras', function () {
    $fields = fn (Form $form) => $form->editor('body');
    expect(fn () => Grid::make(CellItem::class)->quickCreate($fields)->quickForm())->toThrow(InvalidArgumentException::class, 'Upload extras must be flat non-upload fields.')
        ->and(fn () => Action::make('bad', 'Bad')->form($fields))->toThrow(InvalidArgumentException::class, 'Upload extras must be flat non-upload fields.')
        ->and(fn () => Form::make(RichPost::class)->image('cover')->extra($fields))->toThrow(InvalidArgumentException::class, 'Upload extras must be flat non-upload fields.');
});

// Fix round 1: input limits on the write path

function richDeep(int $levels, string $text = 'x'): string
{
    return str_repeat('<div>', $levels).$text.str_repeat('</div>', $levels);
}

function richErrors(Closure $save): array
{
    try {
        $save();
    } catch (ValidationException $e) {
        return $e->errors();
    }
    test()->fail('Expected validation failure');
}

it('rejects over-limit submissions with a translated validation error and never sanitises them', function (Closure $html, string $reason, bool $required) {
    $post = RichPost::create(['body' => '<p>old</p>']);
    $form = richForm($post);
    $field = $form->editor('body', 'Body');
    if ($required) {
        $field->required();
    }
    // An HtmlRejected from clean() would escape richErrors() and fail the test.
    expect(richErrors(fn () => richSave($form, $post, ['body' => $html()])))->toBe(['body' => [__('brume::form.rich_text_'.$reason, ['attribute' => 'Body'])]])
        ->and($post->fresh()->body)->toBe('<p>old</p>');
})->with([
    '2 MB' => [fn () => '<p>'.str_repeat('a', 2_000_000).'</p>', 'bytes'],
    '300 nested divs' => [fn () => richDeep(300), 'depth'],
    '300 nested empty divs' => [fn () => richDeep(300, ''), 'depth'],
    '25,000 flat elements' => [fn () => str_repeat('<b>x</b>', 25_000), 'elements'],
    'a 9 KB attribute value' => [fn () => '<p title="'.str_repeat('a', 9_000).'">x</p>', 'attributes'],
    '300 classes on one element' => [fn () => '<p class="'.implode(' ', range(1000, 1299)).'">x</p>', 'attributes'],
    'a formatting tag with a 1 KB class around 2,000 blocks' => [fn () => '<b class="'.str_repeat('a', 1_000).'">'.str_repeat('<div>x</div>', 2_000), 'bytes'],
])->with(['optional' => false, 'required' => true]);

it('translates the limit messages', function (string $locale) {
    app()->setLocale($locale);
    foreach (['bytes', 'depth', 'elements', 'attributes'] as $reason) {
        expect(__('brume::form.rich_text_'.$reason, ['attribute' => 'X']))->not->toBe('brume::form.rich_text_'.$reason)->toContain('X');
    }
})->with(['en', 'zh_TW', 'zh_CN']);

it('keys over-limit errors to nested json row and relation row paths', function () {
    $post = RichPost::create();
    $section = $post->sections()->create(['body' => '<p>old</p>']);
    $form = richForm($post);
    $form->repeater('blocks', 'Blocks', fn (Form $row) => $row->editor('body', 'Body'));
    expect(array_keys(richErrors(fn () => richSave($form, $post, ['blocks' => [['_brume' => ['key' => 'a'], 'body' => richDeep(300)]]]))))->toBe(['blocks.0.body']);
    $form = richForm($post);
    $form->repeater('sections', 'Sections', fn (Form $row) => $row->editor('body', 'Body'))->relation('sections');
    expect(array_keys(richErrors(fn () => richSave($form, $post, ['sections' => [['_brume' => ['key' => 'record:'.$section->id, 'id' => (string) $section->id], 'body' => '<p>'.str_repeat('a', 2_000_000).'</p>']]]))))->toBe(['sections.0.body'])
        ->and($section->fresh()->body)->toBe('<p>old</p>');
});

// Fix round 1: over-limit values already stored

it('sends null for an over-limit stored value, logs it, and keeps it unless new content replaces it', function () {
    Log::spy();
    $deep = richDeep(400);
    $post = RichPost::create(['body' => $deep, 'title' => 'Old']);
    $build = function () use ($post) {
        $form = richForm($post);
        $form->text('title');
        $form->editor('body');

        return $form;
    };
    Route::get('/rich-edit/{id}', fn ($id) => $build());
    $response = $this->getJson('/rich-edit/'.$post->id, ['X-Brume-Form' => '1'])->assertOk();
    expect($response->json('page.values.body'))->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ($context['field'] ?? null) === 'body' && ($context['reason'] ?? null) === 'depth');
    richSave($build(), $post, ['title' => 'New', 'body' => null]);
    expect($post->fresh()->body)->toBe($deep)->and($post->fresh()->title)->toBe('New');
    richSave($build(), $post, ['body' => '<p><br></p>']);
    expect($post->fresh()->body)->toBe($deep);
    richSave($build(), $post, ['body' => '<p>new</p>']);
    expect($post->fresh()->body)->toBe('<p>new</p>');
});

it('reads an over-limit stored value as null for read-only and conditional fields without throwing', function () {
    $post = RichPost::create(['body' => richDeep(400), 'notes' => '<p>'.str_repeat('a', 400_000).'</p>', 'title' => 'Old']);
    $form = richForm($post);
    $form->text('title');
    $form->editor('body')->readOnly();
    $form->editor('notes')->showWhen(Conditions::eq(Conditions::root('title'), 'show'));
    expect(richPage($form)['values'])->toMatchArray(['body' => null, 'notes' => null]);
    richSave($form, $post, ['title' => 'New']);
    expect($post->fresh()->body)->toBe(richDeep(400))->and($post->fresh()->title)->toBe('New');
});

it('keeps an over-limit value stored in a json row unless new content replaces it', function () {
    $deep = richDeep(400);
    $post = RichPost::create(['blocks' => [['_brume' => ['key' => 'a'], 'body' => $deep, 'other' => 1]]]);
    $build = function () use ($post) {
        $form = richForm($post);
        $form->repeater('blocks', 'Blocks', fn (Form $row) => $row->editor('body'));

        return $form;
    };
    expect(richPage($build())['values']['blocks'][0]['body'])->toBeNull();
    richSave($build(), $post, ['blocks' => [['_brume' => ['key' => 'a'], 'body' => null]]]);
    expect($post->fresh()->blocks[0]['body'])->toBe($deep)->and($post->fresh()->blocks[0]['other'])->toBe(1);
    richSave($build(), $post, ['blocks' => [['_brume' => ['key' => 'a'], 'body' => '<p>new</p>']]]);
    expect($post->fresh()->blocks[0]['body'])->toBe('<p>new</p>');
});

// Fix round 1: Laravel's required family sees an empty document as null

it('applies required_if to empty documents natively', function (string $status, bool $fails) {
    $form = richForm();
    $form->text('status');
    $form->editor('body', 'Body')->rules('required_if:status,published');
    if ($fails) {
        expect(richErrors(fn () => FormValidator::validate($form, ['status' => $status, 'body' => '<p><br></p>'])))->toBe(['body' => [__('validation.required_if', ['attribute' => 'Body', 'other' => 'Status', 'value' => 'published'])]]);
    } else {
        expect(FormValidator::validate($form, ['status' => $status, 'body' => '<p><br></p>']))->toBe(['status' => $status, 'body' => null]);
    }
})->with([['published', true], ['draft', false]]);

it('lets required_with on another field see an empty document as missing', function () {
    $form = richForm();
    $form->editor('body', 'Body');
    $form->text('summary', 'Summary')->rules('required_with:body');
    expect(FormValidator::validate($form, ['body' => '<p>&nbsp;</p>', 'summary' => null]))->toBe(['body' => null, 'summary' => null])
        ->and(array_keys(richErrors(fn () => FormValidator::validate($form, ['body' => '<p>x</p>', 'summary' => null]))))->toBe(['summary']);
});

it('skips required when the editor is hidden by showWhen', function () {
    $form = richForm();
    $form->text('status');
    $form->editor('body', 'Body')->required()->showWhen(Conditions::eq(Conditions::root('status'), 'published'));
    expect(FormValidator::validate($form, ['status' => 'draft', 'body' => '<p><br></p>']))->toBe(['status' => 'draft'])
        ->and(array_keys(richErrors(fn () => FormValidator::validate($form, ['status' => 'published', 'body' => '<p><br></p>']))))->toBe(['body']);
});

it('stores null for an empty nullable document and honours sometimes', function () {
    $post = RichPost::create(['body' => '<p>old</p>', 'title' => 'Old']);
    $form = richForm($post);
    $form->text('title');
    $form->editor('body')->rules('nullable');
    richSave($form, $post, ['body' => '<p><br></p>']);
    expect($post->fresh()->body)->toBeNull();
    $form = richForm();
    $form->editor('body', 'Body')->rules('sometimes|required');
    expect(FormValidator::validate($form, []))->toBe([])
        ->and(array_keys(richErrors(fn () => FormValidator::validate($form, ['body' => '<p><br></p>']))))->toBe(['body']);
});

// Fix round 1: dotted names

it('reads and writes a dotted name on a json attribute', function () {
    $post = RichPost::create(['blocks' => ['content' => RICH_HOSTILE, 'other' => 'keep']]);
    $build = function () use ($post) {
        $form = richForm($post);
        $form->editor('blocks.content');

        return $form;
    };
    $page = richPage($build());
    expectRichSafe($page['values']['blocks']['content']);
    richSave($build(), $post, ['blocks' => ['content' => RICH_HOSTILE]]);
    expect($post->fresh()->blocks)->toBe(['content' => richClean(RICH_HOSTILE), 'other' => 'keep']);
    richSave($build(), $post, ['blocks' => ['content' => '<p><br></p>']]);
    expect($post->fresh()->blocks)->toBe(['content' => null, 'other' => 'keep']);
});

it('reads and writes a dotted name inside a json repeater row', function () {
    $post = RichPost::create(['blocks' => [['_brume' => ['key' => 'a'], 'meta' => ['body' => RICH_HOSTILE, 'x' => 1]]]]);
    $build = function () use ($post) {
        $form = richForm($post);
        $form->repeater('blocks', 'Blocks', fn (Form $row) => $row->editor('meta.body'));

        return $form;
    };
    expectRichSafe(richPage($build())['values']['blocks'][0]['meta']['body']);
    richSave($build(), $post, ['blocks' => [['_brume' => ['key' => 'a'], 'meta' => ['body' => RICH_HOSTILE]]]]);
    expect($post->fresh()->blocks[0]['meta'])->toBe(['body' => richClean(RICH_HOSTILE), 'x' => 1]);
});

// Fix round 1: build-time checks

it('refuses unsafe content stylesheet urls', function (string $url) {
    expect(fn () => Form::make(RichPost::class)->editor('body')->contentCss(['/ok.css', $url]))->toThrow(InvalidArgumentException::class, '用了不允許的協定');
})->with(['javascript:alert(1)', 'data:text/css,body{}', ' JaVaScRiPt:alert(1)', 'mailto:a@example.com']);

it('requires a stable form id when an editor accepts images', function () {
    $form = Form::make(RichPost::class)->submitTo('post', '/posts');
    $form->editor('body');
    expect(fn () => $form->toSchema(Request::create('/')))->toThrow(LogicException::class, 'Configure Form::id()');
    $nested = Form::make(RichPost::class)->submitTo('post', '/posts');
    $nested->repeater('blocks', 'Blocks', fn (Form $row) => $row->editor('body'));
    expect(fn () => $nested->toSchema(Request::create('/')))->toThrow(LogicException::class, 'Configure Form::id()');
    $plain = Form::make(RichPost::class)->submitTo('post', '/posts');
    $plain->editor('body')->withoutImages();
    $plain->repeater('blocks', 'Blocks', fn (Form $row) => $row->editor('body')->withoutImages());
    expect($plain->toSchema(Request::create('/'))['formId'])->toBe('/');
});

it('never serves a cleaned value across sanitiser configurations', function () {
    $html = '<p class="only other">x</p>';
    $post = RichPost::create(['body' => $html]);
    $form = richForm($post);
    $form->editor('body');
    expect(richPage($form)['values']['body'])->toBe($html);
    config(['brume.html.allowed_classes' => ['only']]);
    app()->forgetInstance(HtmlSanitizer::class);
    expect(richPage($form)['values']['body'])->toBe('<p class="only">x</p>');
});
