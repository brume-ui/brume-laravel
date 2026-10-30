<?php

namespace Brume\Form;

use Brume\BrumeManager;
use Brume\Contracts\Renderable;
use Brume\Facades\Brume;
use Brume\Form\Fields\Field;
use Brume\Form\Fields\Upload;
use Brume\Support\Href;
use Closure;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;

class Form implements Renderable, Responsable
{
    protected string $name = '';

    protected ?string $id = null;

    public function id(string $id): static
    {
        if (trim($id) === '') {
            throw new LogicException('Form identity must not be empty.');
        }
        $this->id = $id;

        return $this;
    }

    public function formId(): string
    {
        return $this->id ?? throw new LogicException('Configure Form::id() with a stable identity before using uploads.');
    }

    protected ?string $uploadUrl = null;

    protected ?string $listUrl = null;

    public function uploadUrl(string $url): static
    {
        $this->uploadUrl = Href::checked($url, 'Form::uploadUrl');

        return $this;
    }

    public function listUrl(string $url): static
    {
        $this->listUrl = Href::checked($url, 'Form::listUrl');

        return $this;
    }

    public function toResponse($request)
    {
        $response = $request->hasHeader('X-Brume-Form')
            ? response()->json(['schemaVersion' => BrumeManager::SCHEMA_VERSION, 'page' => $this->toSchema($request)])
            : Brume::render($this)->toResponse($request);
        $response->setVary('X-Brume-Form', false);

        return $response;
    }

    protected ?string $title = null;

    protected ?string $description = null;

    protected string $layout = 'stacked';

    protected string $presentation = 'auto';

    protected string $size = 'auto';

    protected bool $tabs = false;

    protected ?Model $record = null;

    protected ?Closure $recordLabel = null;

    protected ?array $submit = null;

    /** @var list<Field|FormNode> */
    protected array $nodes = [];

    /** @var array<string, Field> */
    protected array $fields = [];

    protected ?self $root = null;

    protected string $prefix = '';

    protected string $context = 'root';

    protected int $sectionSequence = 0;

    protected array $embeds = [];

    protected array $hooks = [];

    protected bool $initialized = false;

    protected ?Closure $urlResolver = null;

    protected ?Closure $viewResolver = null;

    protected array $afterOptions = ['list', 'edit', 'create'];

    protected string $afterDefault = 'list';

    public function creating(Closure $fn): static
    {
        $this->hooks['creating'] = $fn;

        return $this;
    }

    public function editing(Closure $fn): static
    {
        $this->hooks['editing'] = $fn;

        return $this;
    }

    public function submitted(Closure $fn): static
    {
        $this->hooks['submitted'] = $fn;

        return $this;
    }

    public function saving(Closure $fn): static
    {
        $this->hooks['saving'] = $fn;

        return $this;
    }

    public function saved(Closure $fn): static
    {
        $this->hooks['saved'] = $fn;

        return $this;
    }

    public function deleting(Closure $fn): static
    {
        $this->hooks['deleting'] = $fn;

        return $this;
    }

    public function deleted(Closure $fn): static
    {
        $this->hooks['deleted'] = $fn;

        return $this;
    }

    /** Only saving promises mutable values; other hooks retain their value-only contract. */
    public function fireSaving(Model $record, array &$values): mixed
    {
        return isset($this->hooks['saving']) ? ($this->hooks['saving'])($record, $values) : null;
    }

    public function fire(string $event, mixed ...$arguments): mixed
    {
        return isset($this->hooks[$event]) ? ($this->hooks[$event])(...$arguments) : null;
    }

    public function urls(Closure $fn): static
    {
        $this->urlResolver = $fn;

        return $this;
    }

    public function viewUrl(Closure $fn): static
    {
        $this->viewResolver = $fn;

        return $this;
    }

    public function after(array $options = ['list', 'edit', 'create'], string $default = 'list'): static
    {
        foreach ($options as $option) {
            $this->choice($option, ['list', 'edit', 'create', 'view']);
        }
        if ($options === [] || ! in_array($default, $options, true)) {
            throw new InvalidArgumentException('After-save default must be an available option.');
        }
        $this->afterOptions = array_values(array_unique($options));
        $this->afterDefault = $default;

        return $this;
    }

    public function afterSchema(): array
    {
        $options = array_values(array_filter($this->afterOptions, fn ($option) => $option !== 'view' || $this->viewResolver !== null));
        if ($options === []) {
            throw new LogicException('After-save options require a view URL.');
        }

        return ['options' => $options, 'default' => in_array($this->afterDefault, $options, true) ? $this->afterDefault : $options[0]];
    }

    public function saveUrls(Model $record): array
    {
        return AfterSave::urls($this->urlResolver ? ($this->urlResolver)($record) : [], $this->viewResolver ? ($this->viewResolver)($record) : null);
    }

    public function assertSaveUrls(): void
    {
        if ($this->urlResolver === null) {
            throw new LogicException('Configure Form::urls() with an edit URL before saving.');
        }
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;
        if ($this->record === null) {
            $this->fire('creating', $this);
        } else {
            $this->fire('editing', $this->record, $this);
        }
    }

    public function formName(): string
    {
        return $this->name;
    }

    final public function __construct(protected Builder $query) {}

    public static function make(string|Builder $modelOrQuery): static
    {
        return new static(is_string($modelOrQuery) ? $modelOrQuery::query() : $modelOrQuery);
    }

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function description(string $text): static
    {
        $this->description = $text;

        return $this;
    }

    public function tabs(): static
    {
        $this->tabs = true;

        return $this;
    }

    public function recordLabel(Closure $fn): static
    {
        $this->recordLabel = $fn;

        return $this;
    }

    public function forRecord(?Model $record): static
    {
        if ($this->record !== $record) {
            $this->initialized = false;
        }
        $this->record = $record;

        return $this;
    }

    public function record(): ?Model
    {
        return $this->record;
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function layout(string $layout): static
    {
        $this->layout = $this->choice($layout, ['stacked', 'horizontal']);

        return $this;
    }

    public function presentation(string $presentation): static
    {
        $this->presentation = $this->choice($presentation, ['auto', 'modal', 'page']);

        return $this;
    }

    public function size(string $size): static
    {
        $this->size = $this->choice($size, ['auto', 'sm', 'md', 'lg']);

        return $this;
    }

    protected function choice(string $value, array $choices): string
    {
        if (! in_array($value, $choices, true)) {
            throw new InvalidArgumentException('Invalid form setting: '.$value);
        }

        return $value;
    }

    public function submitTo(string $method, string $url): static
    {
        $method = $this->choice(strtolower($method), ['post', 'put']);
        $safe = Href::checked($url, 'Form::submitTo');
        if ($safe === null) {
            throw new InvalidArgumentException('Unsafe form submit URL.');
        }
        $this->submit = ['method' => $method, 'url' => $safe, 'label' => __('brume::form.save')];

        return $this;
    }

    protected function add(Field $field): Field
    {
        if ($this->context === 'columns') {
            throw new InvalidArgumentException('Use column() inside columns().');
        }
        $field->inContainer($this->prefix);
        $root = $this->root ?? $this;
        foreach (array_keys($root->embeds) as $path) {
            if ($path === $field->path() || str_starts_with($path, $field->path().'.')) {
                throw new InvalidArgumentException('Conflicting embeds path: '.$field->path());
            }
        }
        foreach ($root->fields as $path => $existing) {
            if ($path === $field->path() || str_starts_with($path, $field->path().'.') || str_starts_with($field->path(), $path.'.')) {
                throw new InvalidArgumentException('Duplicate or conflicting form field path: '.$field->path());
            }
        }
        $root->fields[$field->path()] = $field;
        $this->nodes[] = $field;

        return $field;
    }

    public function text(string $name, ?string $label = null): Fields\Text
    {
        return $this->add(new Fields\Text($name, $label));
    }

    public function textarea(string $name, ?string $label = null): Fields\Textarea
    {
        return $this->add(new Fields\Textarea($name, $label));
    }

    public function email(string $name, ?string $label = null): Fields\Email
    {
        return $this->add(new Fields\Email($name, $label));
    }

    public function url(string $name, ?string $label = null): Fields\Url
    {
        return $this->add(new Fields\Url($name, $label));
    }

    public function mobile(string $name, ?string $label = null): Fields\Mobile
    {
        return $this->add(new Fields\Mobile($name, $label));
    }

    public function password(string $name, ?string $label = null): Fields\Password
    {
        return $this->add(new Fields\Password($name, $label));
    }

    public function hidden(string $name, ?string $label = null): Fields\Hidden
    {
        return $this->add(new Fields\Hidden($name, $label));
    }

    public function display(string $name, ?string $label = null): Fields\Display
    {
        return $this->add(new Fields\Display($name, $label));
    }

    public function number(string $name, ?string $label = null): Fields\Number
    {
        return $this->add(new Fields\Number($name, $label));
    }

    public function decimal(string $name, ?string $label = null): Fields\Decimal
    {
        return $this->add(new Fields\Decimal($name, $label));
    }

    public function currency(string $name, ?string $label = null): Fields\Currency
    {
        return $this->add(new Fields\Currency($name, $label));
    }

    public function date(string $name, ?string $label = null): Fields\Date
    {
        return $this->add(new Fields\Date($name, $label));
    }

    public function datetime(string $name, ?string $label = null): Fields\Datetime
    {
        return $this->add(new Fields\Datetime($name, $label));
    }

    public function select(string $name, ?string $label = null): Fields\Select
    {
        return $this->add(new Fields\Select($name, $label));
    }

    public function multipleSelect(string $name, ?string $label = null): Fields\MultipleSelect
    {
        return $this->add(new Fields\MultipleSelect($name, $label));
    }

    public function radio(string $name, ?string $label = null): Fields\Radio
    {
        return $this->add(new Fields\Radio($name, $label));
    }

    public function checkbox(string $name, ?string $label = null): Fields\Checkbox
    {
        return $this->add(new Fields\Checkbox($name, $label));
    }

    public function switch(string $name, ?string $label = null): Fields\SwitchField
    {
        return $this->add(new Fields\SwitchField($name, $label));
    }

    public function tags(string $name, ?string $label = null): Fields\Tags
    {
        return $this->add(new Fields\Tags($name, $label));
    }

    public function dateRange(string $name, string $label, string $startColumn, string $endColumn): Fields\DateRange
    {
        return $this->add(new Fields\DateRange($name, $label, $startColumn, $endColumn));
    }

    public function datetimeRange(string $name, string $label, string $startColumn, string $endColumn): Fields\DatetimeRange
    {
        return $this->add(new Fields\DatetimeRange($name, $label, $startColumn, $endColumn));
    }

    public function image(string $name, ?string $label = null): Upload
    {
        return $this->add(new Upload($name, $label, 'image', false));
    }

    public function multipleImage(string $name, ?string $label = null): Upload
    {
        return $this->add(new Upload($name, $label, 'image', true));
    }

    public function file(string $name, ?string $label = null): Upload
    {
        return $this->add(new Upload($name, $label, 'file', false));
    }

    public function multipleFile(string $name, ?string $label = null): Upload
    {
        return $this->add(new Upload($name, $label, 'file', true));
    }

    /** Capture a nested DSL scope, retaining one registry for complete value paths. */
    protected function capture(Closure $fields, string $context, ?string $prefix = null): array
    {
        if (in_array($this->context, ['row', 'columns'], true) && ! ($this->context === 'columns' && $context === 'column')) {
            throw new InvalidArgumentException('Invalid nested form structure in '.$this->context);
        }
        $child = new static($this->query);
        $child->root = $this->root ?? $this;
        $child->prefix = $prefix ?? $this->prefix;
        $child->context = $context;
        $before = $child->root->fields;
        $beforeEmbeds = $child->root->embeds;
        try {
            $fields($child);
        } catch (\Throwable $e) {
            $child->root->fields = $before;
            $child->root->embeds = $beforeEmbeds;
            throw $e;
        }

        return $child->nodes;
    }

    public function section(string $title, Closure $fields, ?string $description = null): static
    {
        $children = $this->capture($fields, 'section');
        $root = $this->root ?? $this;
        $this->nodes[] = new FormNode('section', ['key' => 'section-'.++$root->sectionSequence, 'title' => $title] + ($description !== null ? ['description' => $description] : []), $children);

        return $this;
    }

    public function row(Closure $fields): static
    {
        $this->nodes[] = new FormNode('row', [], $this->capture($fields, 'row'));

        return $this;
    }

    public function columns(Closure $columns): static
    {
        $this->nodes[] = new FormNode('columns', [], $this->capture($columns, 'columns'));

        return $this;
    }

    public function column(int $width, Closure $fields): static
    {
        if ($this->context !== 'columns' || $width < 1 || $width > 12) {
            throw new InvalidArgumentException('column() requires columns() and a width from 1 to 12.');
        }
        $this->nodes[] = new FormNode('column', ['width' => $width], $this->capture($fields, 'column'));

        return $this;
    }

    public function block(?string $title, Closure $fields): static
    {
        $this->nodes[] = new FormNode('block', $title !== null ? ['title' => $title] : [], $this->capture($fields, 'block'));

        return $this;
    }

    public function divider(?string $label = null): static
    {
        if (in_array($this->context, ['row', 'columns'], true)) {
            throw new InvalidArgumentException('Divider cannot be used here.');
        }
        $this->nodes[] = new FormNode('divider', $label !== null ? ['label' => $label] : []);

        return $this;
    }

    public function embeds(string $name, string $label, Closure $fields): static
    {
        Field::assertPath($name);
        $path = $this->prefix.$name;
        $root = $this->root ?? $this;
        foreach (array_keys($root->fields) as $fieldPath) {
            if ($fieldPath === $path || str_starts_with($path, $fieldPath.'.')) {
                throw new InvalidArgumentException('Conflicting embeds path: '.$path);
            }
        }
        if (isset($root->embeds[$path])) {
            throw new InvalidArgumentException('Duplicate embeds path: '.$path);
        }
        $children = $this->capture($fields, 'embeds', $path.'.');
        $root->embeds[$path] = true;
        $this->nodes[] = new FormNode('embeds', ['name' => $name, 'label' => $label], $children);

        return $this;
    }

    /** For upload.extra: structures, dotted names and further uploads are not supported. */
    public function plainFields(): array
    {
        foreach ($this->nodes as $node) {
            if (! $node instanceof Field || $node instanceof Upload || str_contains($node->name(), '.')) {
                throw new InvalidArgumentException('Upload extras must be flat non-upload fields.');
            }
        }

        return $this->nodes;
    }

    public function toSchema(Request $request): array
    {
        if ($this->submit === null) {
            throw new LogicException('Configure Form::submitTo() before rendering.');
        }
        $this->initialize();
        $values = [];
        foreach ($this->fields as $path => $field) {
            data_set($values, $path, $field->valueFor($this->record));
        }
        foreach (array_keys($this->embeds) as $path) {
            // Only declared object containers are cast; list-valued fields keep their arrays.
            data_set($values, $path, (object) (data_get($values, $path) ?? []));
        }
        $count = count($this->fields);
        $long = count(array_filter($this->fields, fn ($field) => $field->isLong())) > 0 || $this->hasEmbeds();
        $presentation = $this->resolvedPresentation();
        $size = $this->size === 'auto' ? ($this->layout === 'horizontal' || $count > 8 ? 'lg' : ($count <= 3 && ! $long ? 'sm' : 'md')) : $this->size;
        $page = [
            'type' => 'form', 'title' => $this->title ?? __($this->record === null ? 'brume::form.create_title' : 'brume::form.edit_title', ['name' => $this->name]),
            'formId' => $this->id ?? (count(array_filter($this->fields, fn ($field) => $field instanceof Upload)) > 0 ? $this->formId() : ($request->route()?->getName() ?? $request->path())), 'name' => $this->name, 'mode' => $this->record === null ? 'create' : 'edit',
            'form' => ['layout' => $this->layout, 'nodes' => array_map(fn ($node) => $node instanceof Field ? ['type' => 'field', 'field' => $node->toSchema($this->record)] : $node->toSchema($this->record), $this->nodes)] + ($this->tabs ? ['sections' => 'tabs'] : []),
            'values' => array_is_list($values) ? (object) $values : $values,
            'submit' => $this->submit, 'after' => $this->afterSchema(), 'presentation' => $presentation, 'size' => $size,
        ];
        if ($this->description !== null) {
            $page['description'] = $this->description;
        }
        if ($this->record !== null) {
            $page['record'] = ['id' => $this->record->getKey()];
            if ($this->recordLabel !== null) {
                $page['record']['label'] = (string) ($this->recordLabel)($this->record);
            }
        }

        if ($this->uploadUrl !== null) {
            $page['uploadUrl'] = $this->uploadUrl;
        }
        if ($this->listUrl !== null) {
            $page['listUrl'] = $this->listUrl;
        }

        return $page;
    }

    /** Structural list metadata; does not initialize the form lifecycle or read model values. */
    public function resolvedPresentation(): string
    {
        $sections = count(array_filter($this->nodes, fn ($node) => $node instanceof FormNode && $node->type === 'section'));

        return $this->presentation === 'auto' ? ($sections >= 3 || count($this->fields) > 16 ? 'page' : 'modal') : $this->presentation;
    }

    protected function hasEmbeds(): bool
    {
        return $this->embeds !== [];
    }
}
