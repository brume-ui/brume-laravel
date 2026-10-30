<?php

namespace Brume\Form;

use Brume\Grid\Actions\Feedback;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class FormSaver
{
    public function save(Form $form, ?Model $record, Request $request): array
    {
        $form->forRecord($record)->initialize();
        $raw = $request->input('values', []);
        if (! is_array($raw)) {
            throw ValidationException::withMessages(['values' => 'Values must be an object.']);
        }
        $feedback = $form->fire('submitted', $raw);
        if ($feedback instanceof Feedback) {
            return ['cancelled' => true, 'feedback' => $feedback->toArray() ?: (object) []];
        }
        $form->assertSaveUrls();
        $values = FormValidator::validate($form, $raw, $record);
        // Retain the nullable create/edit context used by validation.
        $fields = FormValidator::editableFields($form, $record);
        $archiveForm = clone $form;
        if ($record !== null) {
            $stored = clone $record;
            $stored->setRawAttributes($record->getRawOriginal(), true);
            $archiveForm->forRecord($stored);
        }
        $creating = $record === null;
        $record ??= $form->query()->getModel()->newInstance();
        $connection = $record->getConnection();
        $connection->beginTransaction();
        try {
            $feedback = $form->fireSaving($record, $values);
            if ($feedback instanceof Feedback) {
                $connection->rollBack();

                return ['cancelled' => true, 'feedback' => $feedback->toArray() ?: (object) []];
            }
            // Archive transforms upload values before any model writer sees them.
            // A new record is intentionally unsaved here; Task 19 binds create context.
            app(UploadArchiver::class)->archive($archiveForm, $record, $values);
            $this->fill($fields, $record, $values);
            if (! $record->save()) {
                throw new \LogicException('The model refused form persistence.');
            }
            $feedback = $form->fire('saved', $record);
            $urls = $form->saveUrls($record);
            $result = $feedback instanceof Feedback ? $feedback->toArray() : [];
            $result += ['toast' => ['type' => 'success', 'message' => __($creating ? 'brume::form.created' : 'brume::form.updated', ['name' => $form->formName()])]];
            $response = ['id' => $record->getKey(), 'feedback' => $result, 'urls' => $urls];
            $connection->commit();

            return $response;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    protected function fill(array $fields, Model $record, array $values): void
    {
        foreach ($fields as $path => $field) {
            if (Arr::has($values, $path)) {
                $field->fill($record, Arr::get($values, $path));
            }
        }
    }
}
