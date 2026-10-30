<?php

namespace Brume\Uploads;

use Brume\Form\Fields\Upload;
use Brume\Form\Form;
use Brume\Form\FormValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UploadArchiver
{
    public function archive(Form $form, Model $record, array &$values): void
    {
        $plans = [];
        $tokens = [];
        foreach (FormValidator::editableFields($form, $form->record()) as $path => $field) {
            if (! $field instanceof Upload || ! Arr::has($values, $path)) {
                continue;
            }
            $form->formId();
            $field->assertStorage($record);
            if (TempUploads::connection($form) !== $record->getConnection()->getName()) {
                throw new \LogicException('Upload form and record must use the same model connection.');
            }
            $old = data_get($form->record(), $field->attributeName());
            $oldItems = $field->settings()['multiple'] ? (is_array($old) ? $old : []) : [$old];
            $owned = [];
            foreach ($oldItems as $item) {
                $oldPath = is_string($item) ? $item : ($item['path'] ?? null);
                if (is_string($oldPath) && $this->safePath($oldPath)) {
                    $owned[] = $oldPath;
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
                    if (! is_string($token) || isset($tokens[$token]) || ! ($row = app(TempUploads::class)->claim($token, $form, $path))) {
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
                $extra = [];
                if ($field->extraFields() !== []) {
                    if (isset($item['extra']) && ! is_array($item['extra'])) {
                        $this->invalid($key.'.extra');
                    }
                    foreach ($field->extraFields() as $extraField) {
                        if (! $extraField->isEditable($form->record())) {
                            continue;
                        }
                        $name = $extraField->name();
                        $extraValue = $item['extra'][$name] ?? null;
                        $extraInput = [];
                        Arr::set($extraInput, $key.'.extra.'.$name, $extraValue);
                        Validator::make($extraInput, [$key.'.extra.'.$name => $extraField->serverRules($form->record(), $record)])->validate();
                        $extra[$name] = $extraValue;
                    }
                }
                $result[] = $field->extraFields() === [] ? $final : ['path' => $final, 'extra' => (object) $extra];
            }
            $kept = array_map(fn ($item) => is_string($item) ? $item : $item['path'], $result);
            $plans[] = [$path, $field, $multiple ? $result : ($result[0] ?? null), $rows, array_values(array_diff($owned, $kept))];
        }
        if ($plans === []) {
            return;
        }
        $connection = $record->getConnection();
        if (! method_exists($connection, 'afterRollBack') || $connection->transactionLevel() === 0) {
            throw new \LogicException('Uploads require transaction rollback callbacks on the model connection.');
        }
        $new = [];
        $temporary = [];
        $removed = [];
        $connection->afterRollBack(function () use (&$new) {
            foreach ($new as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
        });
        $connection->afterCommit(function () use (&$temporary, &$removed) {
            foreach (array_merge($temporary, $removed) as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
        });
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

    protected function safePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '\\') && ! preg_match('/[\x00-\x1f\x7f]/', $path) && ! in_array('..', explode('/', $path), true) && ! in_array('.', explode('/', $path), true);
    }

    protected function invalid(string $key): never
    {
        throw ValidationException::withMessages([$key => __('brume::form.foreign_upload')]);
    }
}
