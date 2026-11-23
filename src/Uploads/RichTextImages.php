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
 * No brume_uploads row is written and nothing is registered with the cleanup or archiver
 * machinery. An image whose editor session is abandoned, or that is later deleted from the
 * document, stays on disk. That is accepted by design (spec 4): the document is the only
 * reference and scanning HTML for orphans is out of scope.
 */
class RichTextImages
{
    private const DECODABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

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
        if ($extension === null || $extension === 'svg' || str_contains($mime, 'svg') || ! in_array($mime, $accepted, true) || ! $this->decodes($file, $mime)) {
            $this->fail(__('brume::form.upload_type', ['types' => $this->types($accepted)]));
        }
        abort_unless(Auth::guard((string) config('brume.auth.guard'))->check(), 401);

        $disk = Storage::disk($field->diskName());
        $path = $disk->putFileAs(trim($field->directoryFor($formId), '/'), $file, (string) Str::uuid().'.'.$extension);
        if ($path === false) {
            throw new \RuntimeException('Unable to store rich text image.');
        }
        $url = Href::safe($disk->url($path));
        if ($url === null) {
            $disk->delete($path);
            throw new \RuntimeException('The rich text image disk produced an unsafe URL.');
        }

        return ['url' => $url, 'name' => $this->name($file)];
    }

    /**
     * A cheap proof that the bytes are a real image. getimagesize() only reads the header, so
     * `GIF89a<?php ...` still parses; the markup scan refuses such polyglots. A full decode is
     * avoided on purpose: it needs memory proportional to the pixel count.
     */
    private function decodes(UploadedFile $file, string $mime): bool
    {
        if (! in_array($mime, self::DECODABLE, true)) {
            return true;
        }
        $info = @getimagesize($file->getRealPath());
        if ($info === false || ($info['mime'] ?? null) !== $mime) {
            return false;
        }

        return preg_match('/<\?(?:php|=)|<script|<svg|<html/i', (string) file_get_contents($file->getRealPath())) !== 1;
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
