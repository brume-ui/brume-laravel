<?php

namespace Brume\Uploads;

use Brume\Form\Fields\Upload;
use Brume\Form\Form;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class UploadController
{
    public function store(Form $form, Request $request): JsonResponse
    {
        $field = $request->input('field');
        $rowContext = $this->json($request, 'rowContext', []);
        $ancestors = $this->json($request, 'ancestorSources', []);
        if (! is_array($rowContext) || ! is_array($ancestors)) {
            throw ValidationException::withMessages(['rowContext' => 'Invalid upload context.']);
        }
        $rowContext = array_map(fn ($row) => $row instanceof \stdClass ? get_object_vars($row) : $row, $rowContext);
        $definition = is_string($field) ? UploadContext::resolve($form, $field, $rowContext, $ancestors)['field'] : null;
        if (! $definition instanceof Upload) {
            throw ValidationException::withMessages(['field' => 'Invalid upload field.']);
        }
        $request->validate(['file' => 'required|file']);

        return response()->json(app(TempUploads::class)->store($request->file('file'), $form, $field, $definition, $rowContext, $ancestors));
    }

    private function json(Request $request, string $key, mixed $default): mixed
    {
        if (! $request->exists($key)) {
            return $default;
        }
        $raw = $request->input($key);
        if (is_string($raw) && strlen($raw) <= 65536) {
            try {
                return json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
            }
        }
        throw ValidationException::withMessages([$key => 'Invalid upload context.']);
    }

    public function preview(string $token)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/D', $token), 404);
        $guard = (string) config('brume.auth.guard');
        $row = null;
        foreach (TempUploads::connections() as $connection) {
            $row = TempUpload::on($connection)->where('token', $token)->where('guard', $guard)
                ->where('user_id', (string) Auth::guard($guard)->id())->where('expires_at', '>', now())->first();
            if ($row !== null) {
                break;
            }
        }
        abort_if($row === null, 404);
        abort_unless(in_array($row->mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true), 404);
        abort_unless(Storage::disk($row->disk)->exists($row->path), 404);

        return Storage::disk($row->disk)->response($row->path, null, [
            'Content-Type' => $row->mime, 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'", 'Content-Disposition' => 'inline',
        ], 'inline');
    }
}
