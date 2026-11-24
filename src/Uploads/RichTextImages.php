<?php

namespace Brume\Uploads;

use Brume\Form\Fields\RichText;
use Brume\Form\Form;
use Brume\Support\Href;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Images pasted or dropped into a rich text field. Unlike an Upload field they are stored
 * permanently at once: the HTML holds the final URL, so there is no temporary token.
 *
 * The upload disk must be publicly readable with a stable URL (never signed or temporary URLs,
 * which would expire inside saved content) and must never execute the files it serves.
 *
 * No brume_uploads row is written and nothing is registered with the cleanup or archiver
 * machinery. An image whose editor session is abandoned, or that is later deleted from the
 * document, stays on disk. That is accepted by design (spec 4): the document is the only
 * reference and scanning HTML for orphans is out of scope.
 *
 * Safety rests on: the sniffed type is in the raster allow-list, getimagesize() succeeds within
 * the dimension cap, and the stored name and extension are chosen by the server. File bytes are
 * not scanned for markup: legitimate photos match such patterns by chance.
 */
class RichTextImages
{
    /** @return array{url: string, name: string} */
    public function store(UploadedFile $file, Form $form, RichText $field): array
    {
        $formId = $form->formId();
        if (! $field->imagesEnabled()) {
            $this->fail(__('brume::form.rich_text_images_disabled'));
        }
        if (($file->getSize() ?: 0) > $field->maxBytes()) {
            $this->fail(__('brume::form.upload_too_large', ['max' => Number::fileSize($field->maxBytes())]));
        }
        [$mime, $extension] = TempUploads::sniff($file);
        $accepted = $field->acceptedMimes();
        // SVG is never an editor image, whatever the host put in safe_extensions.
        if ($mime === null || $extension === null || $extension === 'svg' || str_contains($mime, 'svg') || ! in_array($mime, $accepted, true)) {
            $this->fail(__('brume::form.upload_type', ['types' => $this->types($accepted)]));
        }
        $info = @getimagesize($file->getRealPath());
        if ($info === false || ($info['mime'] ?? null) !== $mime) {
            $this->fail(__('brume::form.upload_type', ['types' => $this->types($accepted)]));
        }
        $limit = config('brume.uploads.max_image_dimension', 12000);
        if ($limit !== null && max($info[0], $info[1]) > (int) $limit) {
            $this->fail(__('brume::form.rich_text_image_dimensions', ['max' => (int) $limit]));
        }
        abort_unless(Auth::guard((string) config('brume.auth.guard'))->check(), 401);

        $directory = trim($field->directoryFor($formId), '/');
        if (! UploadArchiver::isSafePath($directory)) {
            throw new \LogicException('Unsafe rich text image directory.');
        }
        $disk = Storage::disk($field->diskName());
        $path = $disk->putFileAs($directory, $file, (string) Str::uuid().'.'.$extension);
        if ($path === false) {
            throw new \RuntimeException('Unable to store rich text image.');
        }
        try {
            $url = Href::safe($disk->url($path));
        } catch (\Throwable) {
            $url = null;
        }
        if ($url === null || $url === '') {
            $disk->delete($path);
            $this->fail(__('brume::form.rich_text_no_public_url'));
        }

        return ['url' => $url, 'name' => $this->name($file)];
    }

    private function types(array $mimes): string
    {
        return implode(', ', array_map(fn ($mime) => strtoupper(substr($mime, 6)), $mimes));
    }

    /** The client name is only an alt-text suggestion: reduce it to a printable basename. */
    private function name(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $name));

        return $name === '' || $name === '.' || $name === '..' ? 'image' : mb_strcut($name, 0, 255);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }
}
