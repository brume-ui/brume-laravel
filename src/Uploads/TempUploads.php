<?php

namespace Brume\Uploads;

use Brume\Form\Fields\Upload;
use Brume\Form\Form;
use Brume\Support\Href;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;

class TempUploads
{
    public static function connections(): array
    {
        $names = config('brume.uploads.connections') ?? [config('database.default')];
        if (! is_array($names) || $names === []) {
            throw new \LogicException('Configure uploads.connections with migrated database connections.');
        }
        foreach ($names as $name) {
            if (! is_string($name) || ! is_array(config('database.connections.'.$name))) {
                throw new \LogicException('Unknown upload database connection.');
            }
        }

        return array_values(array_unique($names));
    }

    public static function connection(Form $form): string
    {
        $name = $form->query()->getModel()->getConnection()->getName();
        if (! in_array($name, self::connections(), true)) {
            throw new \LogicException('Add the model connection to brume.uploads.connections and migrate brume_uploads there.');
        }

        return $name;
    }

    public function store(UploadedFile $file, Form $form, string $field, Upload $definition): array
    {
        if (($form->fields()[$field] ?? null) !== $definition || ! $definition->isEditable($form->record())) {
            throw ValidationException::withMessages(['field' => 'Invalid upload field.']);
        }
        $form->formId();
        self::connection($form);
        $settings = $definition->settings();
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:'.($settings['maxSize'] / 1024)]])->validate();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: null;
        $extensions = MimeTypes::getDefault()->getExtensions($mime ?? '');
        if ($mime === null || $extensions === [] || ! in_array($extensions[0], config('brume.uploads.safe_extensions', []), true) || ($settings['accept'] !== [] && ! in_array($mime, $settings['accept'], true))) {
            throw ValidationException::withMessages(['file' => 'Unsupported file type.']);
        }
        $token = Str::random(40);
        $disk = config('brume.uploads.temp_disk', 'local');
        $path = 'brume-tmp/'.$token.'.'.$extensions[0];
        $connection = self::connection($form);
        $guard = (string) config('brume.auth.guard');
        abort_unless(Auth::guard($guard)->check(), 401);
        $stored = Storage::disk($disk)->putFileAs('brume-tmp', $file, basename($path));
        if ($stored === false) {
            throw new \RuntimeException('Unable to store temporary upload.');
        }
        try {
            $row = TempUpload::on($connection)->create([
                'token' => $token, 'form' => $form->formId(), 'field' => $field,
                'user_id' => (string) Auth::guard($guard)->id(), 'guard' => $guard,
                'record' => $form->record()?->getKey() === null ? null : (string) $form->record()->getKey(),
                'disk' => $disk, 'path' => $path, 'original_name' => basename($file->getClientOriginalName()),
                'mime' => $mime, 'size' => $file->getSize(), 'expires_at' => now()->addMinutes(config('brume.uploads.temp_ttl', 1440)), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }

        $response = ['token' => $token, 'name' => $row->original_name, 'size' => $row->size, 'mime' => $mime,
            'expiresAt' => $row->expires_at->toIso8601String()];
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            $response['previewUrl'] = Href::checked(route('brume.uploads.preview', $token), 'upload preview');
        }

        return $response;
    }

    /** Lock and inspect on the model connection; archival consumes the row in that transaction. */
    public function claim(string $token, Form $form, string $field): ?TempUpload
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/D', $token)) {
            return null;
        }
        $guard = (string) config('brume.auth.guard');
        if (! Auth::guard($guard)->check()) {
            return null;
        }

        return TempUpload::on(self::connection($form))
            ->where('token', $token)->where('form', $form->formId())->where('field', $field)
            ->where('guard', $guard)->where('user_id', (string) Auth::guard($guard)->id())
            ->where('record', $form->record()?->getKey() === null ? null : (string) $form->record()->getKey())
            ->where('expires_at', '>', now())->lockForUpdate()->first();
    }
}
