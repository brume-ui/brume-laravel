<?php

namespace Brume\Uploads;

use Brume\Form\Fields\Upload;
use Brume\Form\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Trusted persisted file inventory, independent of editable/visible form fields. */
final class StoredUploadCleanup
{
    public function capture(Form $form, Model $record): array
    {
        $stored = clone $record;
        $stored->setRawAttributes($record->getRawOriginal(), true);
        $files = [];
        foreach ($form->fields() as $field) {
            if (! $field instanceof Upload) {
                continue;
            }
            $value = data_get($stored, $field->attributeName());
            $items = $field->settings()['multiple'] ? (is_array($value) ? $value : []) : [$value];
            foreach ($items as $item) {
                $path = is_string($item) ? $item : (is_array($item) ? ($item['path'] ?? null) : null);
                if (is_string($path) && $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '\\') && ! preg_match('/[\x00-\x1f\x7f]/', $path) && ! in_array('..', explode('/', $path), true) && ! in_array('.', explode('/', $path), true)) {
                    $files[] = [$field->diskName(), $path];
                }
            }
        }

        return $files;
    }

    public function afterHardDelete(Model $record, array $files): void
    {
        if (method_exists($record, 'isForceDeleting') && ! $record->isForceDeleting()) {
            return;
        }
        $record->getConnection()->afterCommit(function () use ($files) {
            foreach ($files as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
        });
    }
}
