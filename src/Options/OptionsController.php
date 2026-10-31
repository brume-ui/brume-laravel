<?php

namespace Brume\Options;

use Brume\Form\DeclarationRegistry;
use Brume\Form\Fields\RelationPicker;
use Brume\Form\Form;
use Brume\Http\ErrorResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Read-only request resolution. Row metadata selects only within authoritative parent storage. */
final class OptionsController
{
    public function respond(Form $form, Request $request)
    {
        try {
            $form->initialize();
            $registry = new DeclarationRegistry($form->fields(), $form->record() ?? $form->query()->getModel());
            $path = $request->query('field');
            $entry = is_string($path) ? ($registry->entries[$path] ?? null) : null;
            $field = $entry['field'] ?? null;
            if ($field === null || ! method_exists($field, 'optionSource') || $field->optionSource() === null) {
                return ErrorResponse::make('Unknown option source.', 404);
            }
            $resolver = new FormOptionContext($form, $registry, $this->json($request, 'ancestorSources', []));
            $owner = $resolver->chain($this->json($request, 'rowContext', []));
            $context = $resolver->resolve($entry, $owner, $this->json($request, 'depends', new stdClass), $this->json($request, 'dependencyContexts', new stdClass));
            $resolver->assertConsumed();

            return response()->json($field->optionSource()->response($context, $request->query(), $field instanceof RelationPicker));
        } catch (ValidationException $e) {
            return ErrorResponse::make($e->getMessage(), 422, $e->errors());
        }
    }

    private function json(Request $request, string $key, mixed $default): mixed
    {
        if (! $request->query->has($key)) {
            return $default;
        }
        $raw = $request->query($key);
        if (! is_string($raw) || strlen($raw) > 65536) {
            $this->invalid($key);
        }
        try {
            return json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->invalid($key);
        }
    }

    private function invalid(string $key): never
    {
        throw ValidationException::withMessages([$key => 'Invalid options context.']);
    }
}
