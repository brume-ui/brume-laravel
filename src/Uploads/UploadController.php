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
        $definition = is_string($field) ? ($form->fields()[$field] ?? null) : null;
        if (! $definition instanceof Upload) {
            throw ValidationException::withMessages(['field' => 'Invalid upload field.']);
        }
        $request->validate(['file' => 'required|file']);

        return response()->json(app(TempUploads::class)->store($request->file('file'), $form, $field, $definition));
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
