<?php

namespace Brume\Uploads;

use Brume\Form\Fields\Upload;
use Brume\Form\Form;
use Brume\Form\FormValidator;
use Brume\Form\JsonValues;
use Brume\Form\Relations\RelationPlan;
use Brume\Uploads\Support\UploadTransactions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UploadArchiver
{
    private ?RelationPlan $relationPlan = null;

    public function archive(Form $form, Model $record, array &$values): void
    {
        $this->process($form, $record, $values, $this->relationPlan);
    }

    public function archivePlan(Form $form, Model $record, array &$values, RelationPlan $plan): void
    {
        // Keep existing host archive() overrides on the Form compatibility binding callable.
        $previous = $this->relationPlan;
        $this->relationPlan = $plan;
        try {
            $this->archive($form, $record, $values);
        } finally {
            $this->relationPlan = $previous;
        }
    }

    private function process(Form $form, Model $record, array &$values, ?RelationPlan $plan): void
    {
        $inventory = $plan?->inventory();
        $original = $inventory === null ? [] : UploadInventory::originals($inventory);
        $plans = [];
        $tokens = [];
        foreach ($inventory['fields'] ?? [] as $path => $entry) {
            if ($entry['field'] instanceof Upload && ($entry['validated'] ?? false) && ! ($entry['active'] ?? false) && Arr::has($values, $path)) {
                Arr::set($values, $path, $original[UploadInventory::key($entry)]['stored'] ?? null);
            }
        }
        $fields = $inventory === null ? FormValidator::editableFields($form, $form->record()) : array_map(fn ($entry) => $entry['field'], array_filter($inventory['fields'] ?? [], fn ($entry) => ($entry['validated'] ?? false) && ($entry['active'] ?? false)));
        foreach ($fields as $path => $field) {
            if (! $field instanceof Upload || ! Arr::has($values, $path)) {
                continue;
            }
            $form->formId();
            $entry = $inventory['fields'][$path] ?? null;
            $callback = $entry === null ? $form->record() : $entry['callback'];
            if (! ($entry['json'] ?? false)) {
                $field->assertStorage($entry['model'] ?? $record);
            }
            if (TempUploads::connection($form) !== $record->getConnection()->getName()) {
                throw new \LogicException('Upload form and record must use the same model connection.');
            }
            $old = $entry === null ? data_get($form->record(), $field->attributeName()) : ($original[UploadInventory::key($entry)]['stored'] ?? null);
            $oldItems = $field->settings()['multiple'] ? (is_array($old) ? $old : []) : [$old];
            $owned = [];
            $storedExtras = [];
            foreach ($oldItems as $item) {
                if ($item instanceof \stdClass) {
                    $item = get_object_vars($item);
                }
                $oldPath = is_string($item) ? $item : ($item['path'] ?? null);
                if (is_string($oldPath) && $this->safePath($oldPath)) {
                    $owned[] = $oldPath;
                    $storedExtra = is_array($item) ? ($item['extra'] ?? []) : [];
                    $storedExtras[$oldPath] = $storedExtra instanceof \stdClass ? get_object_vars($storedExtra) : (is_array($storedExtra) ? $storedExtra : []);
                }
            }
            $input = Arr::get($values, $path);
            $multiple = $field->settings()['multiple'];
            if ($multiple && (! is_array($input) || ! array_is_list($input) || count($input) > ($field->settings()['maxFiles'] ?? PHP_INT_MAX))) {
                $this->invalid($path);
            }
            $items = $multiple ? $input : ($input === null ? [] : [$input]);
            $result = [];
            $rows = [];
            foreach ($items as $index => $item) {
                $key = $multiple ? $path.'.'.$index : $path;
                if (! is_array($item) || (isset($item['token']) === isset($item['path']))) {
                    $this->invalid($key);
                }
                if (isset($item['token'])) {
                    $token = $item['token'];
                    if (! is_string($token) || isset($tokens[$token]) || ! ($row = app(TempUploads::class)->claim($token, $form, $entry['template'] ?? $path, $entry['rowContext'] ?? []))) {
                        $this->invalid($key);
                    }
                    $tokens[$token] = true;
                    if (! $this->safePath($row->path) || ! str_starts_with($row->path, 'brume-tmp/') || ! Storage::disk($row->disk)->exists($row->path)) {
                        $this->invalid($key);
                    }
                    $directory = $field->directoryName() ?? 'brume/'.$form->formId();
                    if (! $this->safePath($directory)) {
                        throw new \LogicException('Upload directory must be a safe relative path.');
                    }
                    $final = $directory.'/'.Str::uuid().'.'.pathinfo($row->path, PATHINFO_EXTENSION);
                    $rows[] = [$row, $final];
                } else {
                    $final = $item['path'];
                    if (! is_string($final) || ! in_array($final, $owned, true)) {
                        $this->invalid($key);
                    }
                }
                // Existing metadata is trusted only after matching an owned stored path.
                $extra = isset($item['path']) ? ($storedExtras[$final] ?? []) : [];
                if ($field->extraFields() !== []) {
                    if (isset($item['extra']) && ! is_array($item['extra'])) {
                        $this->invalid($key.'.extra');
                    }
                    $extraFields = $extraInput = [];
                    foreach ($field->extraFields() as $extraField) {
                        if (! $extraField->isEditable($callback)) {
                            continue;
                        }
                        $name = $extraField->name();
                        $extraPath = $key.'.extra.'.$name;
                        $extraFields[$extraPath] = $extraField;
                        if (array_key_exists($name, $item['extra'] ?? [])) {
                            Arr::set($extraInput, $extraPath, $item['extra'][$name]);
                        }
                    }
                    $validated = FormValidator::validateFields($extraFields, $extraInput, $callback, $entry['model'] ?? $record);
                    foreach ($extraFields as $extraPath => $extraField) {
                        if (Arr::has($validated, $extraPath)) {
                            $name = $extraField->name();
                            $extra[$name] = $extraField->storeExtraValue(Arr::get($validated, $extraPath), $extraField->type() === 'custom' ? ($extra[$name] ?? null) : JsonValues::validationView($extra[$name] ?? null));
                        }
                    }
                }
                $result[] = $field->extraFields() === [] ? $final : ['path' => $final, 'extra' => (object) $extra];
            }
            $kept = array_map(fn ($item) => is_string($item) ? $item : $item['path'], $result);
            $plans[] = [$path, $field, $multiple ? $result : ($result[0] ?? null), $rows, array_values(array_diff($owned, $kept))];
        }
        $removed = $inventory === null ? [] : app(StoredUploadCleanup::class)->removed($inventory);
        if ($plans === [] && $removed === []) {
            return;
        }
        $connection = $record->getConnection();
        $new = [];
        $temporary = [];
        app(UploadTransactions::class)->register($connection,
            function () use (&$temporary, &$removed) {
                $this->cleanup(array_merge($temporary, $removed));
            },
            function () use (&$new) {
                $this->cleanup($new);
            },
        );
        foreach ($plans as [$path, $field, $result, $rows, $deleted]) {
            foreach ($rows as [$row, $final]) {
                $disk = $field->diskName();
                $new[] = [$disk, $final];
                $stream = Storage::disk($row->disk)->readStream($row->path);
                try {
                    if ($stream === false || ! Storage::disk($disk)->put($final, $stream)) {
                        throw new \RuntimeException('Unable to archive upload.');
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                // Delete under the row lock in the model transaction, closing replay before commit callbacks.
                if (! $row->delete()) {
                    throw new \RuntimeException('Unable to consume temporary upload.');
                }
                $temporary[] = [$row->disk, $row->path];
            }
            foreach ($deleted as $deletedPath) {
                $removed[] = [$field->diskName(), $deletedPath];
            }
            Arr::set($values, $path, $result);
        }
    }

    protected function cleanup(array $files): void
    {
        app(StoredUploadCleanup::class)->deleteFiles($files);
    }

    protected function safePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '\\') && ! preg_match('/[\x00-\x1f\x7f]/', $path) && ! in_array('..', explode('/', $path), true) && ! in_array('.', explode('/', $path), true);
    }

    protected function invalid(string $key): never
    {
        throw ValidationException::withMessages([$key => __('brume::form.foreign_upload')]);
    }
}
