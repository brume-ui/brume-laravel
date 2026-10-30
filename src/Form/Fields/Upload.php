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
    protected string $diskName = 'public';

    protected string $directoryName = 'uploads';

    protected array $extraFields = [];

    public function __construct(string $name, ?string $label = null, string $kind = 'image', bool $multiple = false)
    {
        parent::__construct($name, $label);
        $this->schema = ['kind' => $kind, 'multiple' => $multiple, 'accept' => $kind === 'image' ? ['image/jpeg', 'image/png', 'image/webp', 'image/gif'] : [], 'maxSize' => 10 * 1024 * 1024];
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
        return $this->diskName;
    }

    public function directoryName(): string
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
        $path = is_string($value) ? $value : ($value['path'] ?? null);
        if (! is_string($path) || $path === '') {
            return null;
        }
        $url = Href::safe(Storage::disk($this->diskName)->url($path));
        if ($url === null) {
            return null;
        }
        $result = ['path' => $path, 'url' => $url];
        if ($this->extraFields !== []) {
            $extra = [];
            $stored = is_array($value) ? ($value['extra'] ?? []) : [];
            foreach ($this->extraFields as $field) {
                // Reuse field normalization and hidden/password rules without copying undeclared metadata.
                $record = new class extends Model {};
                $record->forceFill($stored);
                $extra[$field->name()] = $field->valueFor($record);
            }
            $result['extra'] = $extra === [] ? (object) [] : $extra;
        }

        return $result;
    }
}
