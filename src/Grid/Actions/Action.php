<?php

namespace Brume\Grid\Actions;

use Brume\Form\Form;
use Brume\Form\FormValidator;
use Brume\Form\JsonValues;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * A row, batch or tool action of a Grid (Grid::rowActions(), batchActions(), tools()). It runs on
 * the endpoints.action endpoint with the rows the request names, looked up in the grid's query.
 *
 * The work is the handle() closure, or run() in a subclass: it receives the models (one for a row
 * action, the selection for a batch action, none for a tool) and the request, runs inside a
 * database transaction, and may return Feedback.
 */
class Action
{
    /** Shortcut names besides a single character. */
    public const SHORTCUT_KEYS = ['Enter', 'Delete', 'Backspace'];

    protected ?string $icon = null;

    protected bool $danger = false;

    /** @var array{title: string, description?: string, confirmLabel: string}|null */
    protected ?array $confirm = null;

    protected ?string $shortcut = null;

    protected bool $exposed = false;

    protected ?Closure $visible = null;

    protected ?Closure $handler = null;

    protected ?Form $inputForm = null;

    protected ?string $formTitle = null;

    protected ?string $submitLabel = null;

    protected array $validatedInput = [];

    public function form(Closure $fields, ?string $title = null, ?string $submitLabel = null): static
    {
        $model = new class extends Model {};
        $form = Form::make($model->newQuery());
        $fields($form);
        $form->initialize();
        $form->plainFields();
        $this->inputForm = $form;
        $this->formTitle = $title;
        $this->submitLabel = $submitLabel;

        return $this;
    }

    public function validateInput(Request $request, ?Model $model = null): array
    {
        if ($this->inputForm === null) {
            return [];
        }
        $raw = JsonValues::requestValue($request, 'input', []);
        if ($raw instanceof \stdClass) {
            $raw = get_object_vars($raw);
        } elseif ($request->isJson()) {
            throw ValidationException::withMessages(['input' => 'Input must be an object.']);
        }
        if (! is_array($raw)) {
            throw ValidationException::withMessages(['input' => 'Input must be an object.']);
        }

        return FormValidator::validateFields($this->inputForm->fields(), $raw, null, $model);
    }

    final public function __construct(protected string $key, protected string $label)
    {
        if ($key === '') {
            throw new InvalidArgumentException('Action 的 key 不能是空字串。');
        }
    }

    public static function make(string $key, string $label): static
    {
        return new static($key, $label);
    }

    /**
     * A lucide icon name in kebab-case, e.g. 'trash-2'.
     */
    public function icon(string $lucide): static
    {
        $this->icon = $lucide;

        return $this;
    }

    public function danger(): static
    {
        $this->danger = true;

        return $this;
    }

    /**
     * Ask before running. {count} in the texts becomes the number of rows the action runs on. The
     * confirm button reads the action's label unless $confirmLabel is given.
     */
    public function confirm(string $title, ?string $description = null, ?string $confirmLabel = null): static
    {
        $this->confirm = ['title' => $title]
            + ($description !== null ? ['description' => $description] : [])
            + ['confirmLabel' => $confirmLabel ?? $this->label];

        return $this;
    }

    /**
     * A single character, or 'Enter', 'Delete' or 'Backspace'.
     */
    public function shortcut(string $key): static
    {
        if (mb_strlen($key) !== 1 && ! in_array($key, self::SHORTCUT_KEYS, true)) {
            throw new InvalidArgumentException("快速鍵「{$key}」必須是單一字元，或 ".implode('、', self::SHORTCUT_KEYS).' 之一。');
        }

        $this->shortcut = $key;

        return $this;
    }

    /**
     * Show the row action as a button on the row instead of only in the ⋯ menu (two at most).
     */
    public function exposed(): static
    {
        $this->exposed = true;

        return $this;
    }

    /**
     * Whether the action is offered on a row: fn (Model $row): bool. A row it returns false for is
     * left out of _meta.actions, and a request that names it is refused with 403.
     */
    public function visible(Closure $fn): static
    {
        $this->visible = $fn;

        return $this;
    }

    /**
     * The work: fn (Collection $models, Request $request, array $input): ?Feedback.
     */
    public function handle(Closure $fn): static
    {
        $this->handler = $fn;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @internal
     */
    public function isExposed(): bool
    {
        return $this->exposed;
    }

    /**
     * @internal
     */
    public function hasVisibility(): bool
    {
        return $this->visible !== null;
    }

    /**
     * @internal
     */
    public function isVisibleFor(Model $row): bool
    {
        return $this->visible === null || (bool) ($this->visible)($row);
    }

    /**
     * Run the action on $models; ActionRunner calls this inside a transaction.
     *
     * @internal
     *
     * @param  Collection<int, Model>  $models
     */
    public function execute(Collection $models, Request $request, array $input = []): ?Feedback
    {
        $this->validatedInput = $input;

        return $this->run($models, $request);
    }

    /**
     * The ActionDef.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return ['key' => $this->key, 'label' => $this->label]
            + ($this->inputForm !== null ? ['form' => ['fields' => array_map(fn ($field) => $field->toSchema(null), $this->inputForm->plainFields())] + ($this->formTitle !== null ? ['title' => $this->formTitle] : []) + ($this->submitLabel !== null ? ['submitLabel' => $this->submitLabel] : [])] : [])
            + ($this->icon !== null ? ['icon' => $this->icon] : [])
            + ($this->danger ? ['tone' => 'danger'] : [])
            + ($this->confirm !== null ? ['confirm' => $this->confirm] : [])
            + ($this->shortcut !== null ? ['shortcut' => $this->shortcut] : [])
            + ($this->exposed ? ['exposed' => true] : []);
    }

    /**
     * @param  Collection<int, Model>  $models
     */
    protected function run(Collection $models, Request $request): ?Feedback
    {
        if ($this->handler === null) {
            throw new LogicException("Action「{$this->key}」沒有 handle()，請用 ->handle(fn (Collection \$models, Request \$request) => ...) 指定要做的事，或在子類別覆寫 run()。");
        }

        $result = ($this->handler)($models, $request, $this->validatedInput);

        if ($result !== null && ! $result instanceof Feedback) {
            throw new LogicException("Action「{$this->key}」的 handle() 必須回傳 Feedback 或 null，目前回傳 ".get_debug_type($result).'。');
        }

        return $result;
    }
}
