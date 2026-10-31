<?php

namespace Brume\Uploads;

use Brume\Form\DeclarationRegistry;
use Brume\Form\Fields\Upload;
use Brume\Form\Form;
use Brume\Options\FormOptionContext;
use Illuminate\Validation\ValidationException;

/** Resolve a numeric error location through declarations and verified parent ownership. */
final class UploadContext
{
    public static function resolve(Form $form, string $path, array $chain = [], array $ancestors = []): array
    {
        $form->initialize();
        $registry = new DeclarationRegistry($form->fields(), $form->record() ?? $form->query()->getModel());
        foreach ($registry->entries as $template => $entry) {
            if (! $entry['field'] instanceof Upload || ! preg_match('~^'.str_replace('\\*', '(?:0|[1-9][0-9]*)', preg_quote($template, '~')).'$~D', $path)) {
                continue;
            }
            $resolver = new FormOptionContext($form, $registry, $ancestors);
            $chain = $resolver->chain(json_decode(json_encode($chain, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR));
            [$callback, $json, $locked, $storage] = $resolver->uploadContext($entry, $chain);
            $resolver->assertConsumed();
            if ($locked || ! $entry['field']->isEditable($callback)) {
                break;
            }
            if ($storage !== null && $storage->getConnection()->getName() !== TempUploads::connection($form)) {
                throw ValidationException::withMessages(['rowContext' => 'Mixed upload connections are not supported.']);
            }

            return ['field' => $entry['field'], 'template' => $template, 'rowContext' => $chain, 'callback' => $callback, 'owner' => $storage];
        }
        throw ValidationException::withMessages(['field' => 'Invalid upload field.']);
    }
}
