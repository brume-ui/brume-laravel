<?php

use Brume\Form\Fields\RichText;
use Brume\Form\Form;
use Brume\Grid\Grid;
use Brume\Http\Middleware\HandleBrumeRequests;
use Brume\Resource\Resource;
use Brume\Tests\Support\JsonSchema;
use Brume\Uploads\UploadController;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RichImagePost extends Model
{
    protected $table = 'rich_posts';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['blocks' => 'array'];

    public function sections()
    {
        return $this->hasMany(RichImageSection::class, 'post_id');
    }
}
class RichImageSection extends Model
{
    protected $table = 'rich_sections';

    protected $guarded = [];

    public $timestamps = false;
}

class RichImageResource extends Resource
{
    public static ?Closure $configure = null;

    protected string $model = RichImagePost::class;

    protected string $name = 'Posts';

    protected function grid(Grid $grid): void
    {
        $grid->column('title');
    }

    protected function form(Form $form): void
    {
        (self::$configure ?? fn (Form $f) => $f->editor('body'))($form);
    }
}

beforeEach(function () {
    RichImageResource::$configure = null;
    Storage::fake('local');
    Storage::fake('public');
    $this->actingAs(brumeUser(), 'brume');
    Route::prefix('admin')->middleware(['web', 'brume.auth', HandleBrumeRequests::class])->group(fn () => Route::brumeResource('rich-images', RichImageResource::class));
    Route::getRoutes()->refreshNameLookups();
});

function richImage(string $name = 'photo.png', int $kb = 0): UploadedFile
{
    return UploadedFile::fake()->image($name, 8, 8)->size($kb ?: 1);
}

function richUpload($test, ?UploadedFile $file, string $field = 'body', array $extra = [], string $url = '/admin/rich-images/_brume/upload')
{
    return $test->post($url, ['field' => $field, 'file' => $file] + $extra, ['Accept' => 'application/json']);
}

function richConfigure(Closure $configure): void
{
    RichImageResource::$configure = $configure;
}

it('stores the image permanently and answers with its url', function () {
    $response = richUpload($this, UploadedFile::fake()->image('photo.png'))->assertOk();
    $files = Storage::disk('public')->allFiles();
    expect($files)->toHaveCount(1)->and($files[0])->toMatch('~^brume/rich-images/editor/[0-9a-f-]{36}\.png$~');
    expect($response->json('url'))->toBe(Storage::disk('public')->url($files[0]))->and($response->json('name'))->toBe('photo.png');
    expect(Storage::disk('local')->allFiles())->toBe([]);
    $this->assertDatabaseCount('brume_uploads', 0);
    expect(JsonSchema::errors($response->json(), 'RichTextImageResponse'))->toBe([]);
});

it('accepts jpeg png gif and webp by default', function (string $name, string $ext) {
    $file = $ext === 'webp'
        ? UploadedFile::fake()->createWithContent($name, (string) base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA=='))
        : UploadedFile::fake()->image($name, 8, 8);
    richUpload($this, $file)->assertOk();
    expect(Storage::disk('public')->allFiles()[0])->toEndWith('.'.$ext);
})->with([['a.jpg', 'jpg'], ['a.png', 'png'], ['a.gif', 'gif'], ['a.webp', 'webp']]);

it('derives the stored name from the sniffed type, not the client', function () {
    $response = richUpload($this, UploadedFile::fake()->image('../../evil.php.jpg', 8, 8)->mimeType('image/jpeg'))->assertOk();
    $files = Storage::disk('public')->allFiles();
    expect($files)->toHaveCount(1)->and($files[0])->toMatch('~^brume/rich-images/editor/[0-9a-f-]{36}\.(png|jpg)$~');
    expect($response->json('name'))->toBe('evil.php.jpg');
});

it('stores two uploads of the same file separately', function () {
    $file = fn () => UploadedFile::fake()->image('same.png');
    $a = richUpload($this, $file())->assertOk()->json('url');
    $b = richUpload($this, $file())->assertOk()->json('url');
    expect($a)->not->toBe($b)->and(Storage::disk('public')->allFiles())->toHaveCount(2);
});

it('rejects a php file renamed to jpg', function () {
    richUpload($this, UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]);'))->assertUnprocessable()->assertJsonStructure(['errors' => ['file']]);
    expect(Storage::disk('public')->allFiles())->toBe([])->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('rejects a file whose body is not an image', function () {
    richUpload($this, UploadedFile::fake()->createWithContent('trunc.gif', 'GIF89a'))->assertUnprocessable();
    richUpload($this, UploadedFile::fake()->createWithContent('trunc.png', "\x89PNG\r\n\x1a\n garbage"))->assertUnprocessable();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('stores an accepted gif header polyglot only under a server chosen name', function () {
    config(['brume.uploads.max_image_dimension' => null]);
    $response = richUpload($this, UploadedFile::fake()->createWithContent('../../shell.php.gif', 'GIF89a<?php system($_GET["c"]); ?>'))->assertOk();
    $files = Storage::disk('public')->allFiles();
    expect($files)->toHaveCount(1)->and($files[0])->toMatch('~^brume/rich-images/editor/[0-9a-f-]{36}\.gif$~');
    expect($response->json('url'))->not->toContain('shell')->and($files[0])->not->toContain('php');
});

it('rejects absurd pixel dimensions by default', function () {
    // 50000 x 50000 PNG header (IHDR only; getimagesize reads the header).
    $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 50000, 50000, 8, 2, 0, 0, 0).pack('N', crc32('IHDR'.pack('NNCCCCC', 50000, 50000, 8, 2, 0, 0, 0)));
    richUpload($this, UploadedFile::fake()->createWithContent('huge.png', $png))->assertUnprocessable();
    richUpload($this, UploadedFile::fake()->createWithContent('poly.gif', 'GIF89a<?php system(1); ?>'))->assertUnprocessable();
    expect(Storage::disk('public')->allFiles())->toBe([]);
    config(['brume.uploads.max_image_dimension' => 4]);
    richUpload($this, UploadedFile::fake()->image('a.png', 8, 8))->assertUnprocessable();
    richUpload($this, UploadedFile::fake()->image('a.png', 4, 4))->assertOk();
});

it('accepts a normal directory and refuses an unsafe one at use time', function () {
    richConfigure(fn (Form $f) => $f->editor('body')->directory('a/b/c'));
    richUpload($this, UploadedFile::fake()->image('a.png'))->assertOk();
    expect(Storage::disk('public')->allFiles()[0])->toStartWith('a/b/c/');
});

it('answers 422 and leaves no file when the disk has no public url', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('putFileAs')->once()->andReturn('brume/rich-images/editor/x.png');
    $disk->shouldReceive('url')->andThrow(new RuntimeException('no url'));
    $disk->shouldReceive('delete')->once()->with('brume/rich-images/editor/x.png');
    Storage::set('nourl', $disk);
    richConfigure(fn (Form $f) => $f->editor('body')->disk('nourl'));
    $response = richUpload($this, UploadedFile::fake()->image('a.png'))->assertUnprocessable();
    expect($response->json('errors.file.0'))->toBe(__('brume::form.rich_text_no_public_url'));
});

it('never accepts svg', function () {
    config(['brume.uploads.safe_extensions' => [...config('brume.uploads.safe_extensions'), 'svg']]);
    $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';
    richUpload($this, UploadedFile::fake()->createWithContent('a.svg', $svg))->assertUnprocessable();
    richUpload($this, UploadedFile::fake()->createWithContent('a.png', $svg))->assertUnprocessable();
    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect(fn () => (new RichText('body', 'Body'))->accept(['image/svg+xml']))->toThrow(InvalidArgumentException::class);
});

it('rejects oversize files', function () {
    richConfigure(fn (Form $f) => $f->editor('body')->maxSize(2));
    richUpload($this, UploadedFile::fake()->image('big.png', 8, 8)->size(3))->assertUnprocessable()->assertJsonStructure(['errors' => ['file']]);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects uploads when images are disabled', function () {
    richConfigure(fn (Form $f) => $f->editor('body')->withoutImages());
    $response = richUpload($this, UploadedFile::fake()->image('a.png'))->assertUnprocessable();
    expect($response->json('errors.file.0'))->toBe(__('brume::form.rich_text_images_disabled'));
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('has translated messages in every locale', function (string $locale) {
    app()->setLocale($locale);
    foreach (['rich_text_no_public_url', 'rich_text_image_dimensions'] as $key) {
        expect(__('brume::form.'.$key))->not->toBe('brume::form.'.$key);
    }
    expect(__('brume::form.rich_text_images_disabled'))->not->toBe('brume::form.rich_text_images_disabled');
})->with(['en', 'zh_TW', 'zh_CN']);

it('honours a custom disk directory and accept list', function () {
    Storage::fake('media');
    richConfigure(fn (Form $f) => $f->editor('body')->disk('media')->directory('posts/inline/')->accept(['image/png']));
    $response = richUpload($this, UploadedFile::fake()->image('a.png'))->assertOk();
    $files = Storage::disk('media')->allFiles();
    expect($files)->toHaveCount(1)->and($files[0])->toMatch('~^posts/inline/[0-9a-f-]{36}\.png$~')->and(Storage::disk('public')->allFiles())->toBe([]);
    expect($response->json('url'))->toBe(Storage::disk('media')->url($files[0]));
    richUpload($this, UploadedFile::fake()->image('a.jpg'))->assertUnprocessable();
    expect(Storage::disk('media')->allFiles())->toHaveCount(1);
});

it('keeps the upload field flow on temporary storage', function () {
    richConfigure(function (Form $f) {
        $f->editor('body');
        $f->image('title');
    });
    $response = richUpload($this, UploadedFile::fake()->image('a.png'), 'title')->assertOk();
    expect($response->json('token'))->toBeString()->and($response->json())->not->toHaveKey('url');
    expect(Storage::disk('public')->allFiles())->toBe([]);
    $this->assertDatabaseCount('brume_uploads', 1);
    expect(JsonSchema::errors($response->json(), 'UploadTempResponse'))->toBe([]);
});

it('refuses forged paths and non upload fields as before', function (string $field) {
    richConfigure(function (Form $f) {
        $f->editor('body');
        $f->text('title');
    });
    richUpload($this, UploadedFile::fake()->image('a.png'), $field)->assertUnprocessable()->assertJsonPath('errors.field.0', 'Invalid upload field.');
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with(['title', 'missing', 'body.0', 'blocks.0.body']);

it('refuses read only and disabled editors like an upload field', function (string $mode) {
    richConfigure(function (Form $f) use ($mode) {
        $editor = $f->editor('body');
        $mode === 'readonly' ? $editor->readOnly() : $editor->disabled();
    });
    richUpload($this, UploadedFile::fake()->image('a.png'))->assertUnprocessable()->assertJsonPath('errors.field.0', 'Invalid upload field.');
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with(['readonly', 'disabled']);

it('requires authentication like an upload field', function () {
    richConfigure(function (Form $f) {
        $f->editor('body');
        $f->image('title');
    });
    auth('brume')->logout();
    $rich = richUpload($this, UploadedFile::fake()->image('a.png'));
    $plain = richUpload($this, UploadedFile::fake()->image('a.png'), 'title');
    expect($rich->status())->toBe($plain->status())->and($rich->status())->not->toBe(200);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('works for an editor inside a json repeater row', function () {
    richConfigure(fn (Form $f) => $f->repeater('blocks', 'Blocks', fn (Form $row) => $row->editor('body')));
    $post = RichImagePost::create(['blocks' => [['body' => '<p>x</p>']]]);
    $stored = $this->get('/admin/rich-images/'.$post->id.'/edit', ['X-Brume-Form' => '1'])->assertOk()->json('page.values.blocks.0._brume.key');
    $context = json_encode([['path' => 'blocks', 'key' => $stored]]);
    $response = richUpload($this, UploadedFile::fake()->image('n.png'), 'blocks.0.body', ['rowContext' => $context], '/admin/rich-images/'.$post->id.'/_brume/upload')->assertOk();
    expect($response->json('url'))->toBeString()->and(Storage::disk('public')->allFiles())->toHaveCount(1);
    richUpload($this, UploadedFile::fake()->image('n.png'), 'blocks.0.body', ['rowContext' => json_encode([['path' => 'blocks', 'key' => 'x']])], '/admin/rich-images/'.$post->id.'/_brume/upload')->assertOk();
    richUpload($this, UploadedFile::fake()->image('n.png'), 'blocks.0.body', ['rowContext' => '[]'], '/admin/rich-images/'.$post->id.'/_brume/upload')->assertUnprocessable();
    expect(Storage::disk('public')->allFiles())->toHaveCount(2);
});

it('works for an editor inside a relation row and refuses a foreign row', function () {
    richConfigure(fn (Form $f) => $f->repeater('sections', 'Sections', fn (Form $row) => $row->editor('body'))->relation('sections'));
    $post = RichImagePost::create();
    $mine = $post->sections()->create(['body' => '<p>a</p>']);
    $other = RichImagePost::create()->sections()->create(['body' => '<p>b</p>']);
    $url = '/admin/rich-images/'.$post->id.'/_brume/upload';
    $context = fn ($row) => json_encode([['path' => 'sections', 'key' => 'record:'.$row->id, 'id' => (string) $row->id]]);
    richUpload($this, UploadedFile::fake()->image('n.png'), 'sections.0.body', ['rowContext' => $context($mine)], $url)->assertOk();
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
    richUpload($this, UploadedFile::fake()->image('n.png'), 'sections.0.body', ['rowContext' => $context($other)], $url)->assertUnprocessable();
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('calls the controller directly for a nested row without an http stack', function () {
    $form = Form::make(RichImagePost::class)->id('posts');
    $form->repeater('blocks', 'Blocks', fn (Form $row) => $row->editor('body'));
    $request = Request::create('/', 'POST', ['field' => 'blocks.0.body', 'rowContext' => json_encode([['path' => 'blocks', 'key' => 'k']])], [], ['file' => UploadedFile::fake()->image('n.png')]);
    expect(app(UploadController::class)->store($form, $request)->getData(true))->toHaveKeys(['url', 'name']);
    $bad = Request::create('/', 'POST', ['field' => 'blocks.0.body', 'rowContext' => '{'], [], ['file' => UploadedFile::fake()->image('n.png')]);
    expect(fn () => app(UploadController::class)->store($form, $bad))->toThrow(ValidationException::class);
});
