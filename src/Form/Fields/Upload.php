<?php

namespace Brume\Form\Fields;

use Brume\Form\Form;
use Brume\Support\Href;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class Upload extends Field
{
    protected ?string $diskName = null;

    protected ?string $directoryName = null;

    protected array $extraFields = [];

    public function __construct(string $name, ?string $label = null, string $kind = 'image', bool $multiple = false)
    {
        parent::__construct($name, $label);
        $this->schema = ['kind' => $kind, 'multiple' => $multiple, 'accept' => $kind === 'image' ? ['image/jpeg', 'image/png', 'image/webp', 'image/gif'] : [], 'maxSize' => config('brume.uploads.max_size', 10240) * 1024];
    }

    public function impliedRules(): array
    {
        return array_merge(parent::impliedRules(), $this->schema['multiple'] ? [] : ['array']);
    }

    public function assertStorage(Model $record): void
    {
        if ($this->writer !== null) {
            return;
        }
        $root = explode('.', $this->attributeName(), 2)[0];
        if (($this->schema['multiple'] || $this->extraFields !== [] || str_contains($this->attributeName(), '.')) && ! $record->hasCast($root, ['array', 'json'])) {
            throw new \LogicException('Structured uploads require an array or json cast: '.$root);
        }
    }

    public function type(): string
    {
        return 'upload';
    }

    public function disk(string $disk): static
    {
        $this->diskName = $disk;

        return $this;
    }

    public function directory(string $directory): static
    {
        $this->directoryName = $directory;

        return $this;
    }

    public function accept(array $mimes): static
    {
        $this->schema['accept'] = array_values($mimes);

        return $this;
    }

    public function maxSize(int $kilobytes): static
    {
        if ($kilobytes < 1) {
            throw new InvalidArgumentException('Max upload size must be positive.');
        }
        $this->schema['maxSize'] = $kilobytes * 1024;

        return $this;
    }

    public function max(int $files): static
    {
        if ($files < 1) {
            throw new InvalidArgumentException('Max files must be positive.');
        }
        $this->schema['maxFiles'] = $files;

        return $this;
    }

    public function sortable(bool $on = true): static
    {
        $this->schema['sortable'] = $on;

        return $this;
    }

    public function diskName(): string
    {
        return $this->diskName ?? config('brume.uploads.disk', 'public');
    }

    public function directoryName(): ?string
    {
        return $this->directoryName;
    }

    public function extraFields(): array
    {
        return $this->extraFields;
    }

    public function extra(Closure $fields): static
    {
        $form = Form::make((new class extends Model {})->newQuery());
        $fields($form);
        $this->extraFields = $form->plainFields();

        return $this;
    }

    public function toSchema(?Model $record = null): array
    {
        return parent::toSchema($record) + ($this->extraFields !== [] ? ['extra' => array_map(fn (Field $field) => $field->toSchema($record), $this->extraFields)] : []);
    }

    protected function normalizeValue(mixed $value): mixed
    {
        if ($this->schema['multiple']) {
            return array_values(array_filter(array_map(fn ($item) => $this->archivedValue($item), $value ?? []), fn ($item) => $item !== null));
        }

        return $this->archivedValue($value);
    }

    protected function archivedValue(mixed $value): ?array
    {
        // Normalize structural envelopes only; declared extra values retain their semantic JSON.
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        $path = is_string($value) ? $value : ($value['path'] ?? null);
        if (! is_string($path) || $path === '') {
            return null;
        }
        $url = Href::safe(Storage::disk($this->diskName())->url($path));
        if ($url === null) {
            return null;
        }
        $result = ['path' => $path, 'url' => $url];
        if ($this->extraFields !== []) {
            $extra = [];
            $stored = is_array($value) ? ($value['extra'] ?? []) : [];
            $stored = $stored instanceof \stdClass ? get_object_vars($stored) : (is_array($stored) ? $stored : []);
            foreach ($this->extraFields as $field) {
                $extra[$field->name()] = $field->extraValueFor($stored[$field->name()] ?? null);
            }
            $result['extra'] = (object) $extra;
        }

        return $result;
    }
}
