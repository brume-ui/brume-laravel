<?php

namespace Brume\Form;

use Brume\Form\Relations\RelationPlanner;
use Brume\Form\Relations\RelationSynchronizer;
use Brume\Grid\Actions\Feedback;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class FormSaver
{
    public function save(Form $form, ?Model $record, Request $request): array
    {
        $raw = JsonValues::requestValue($request, 'values', []);
        if ($raw instanceof \stdClass) {
            $raw = get_object_vars($raw);
        } elseif ($request->isJson()) {
            throw ValidationException::withMessages(['values' => 'Values must be an object.']);
        }
        if (! is_array($raw)) {
            throw ValidationException::withMessages(['values' => 'Values must be an object.']);
        }
        $creating = $record === null;
        $record ??= $form->query()->getModel()->newInstance();
        $connection = $record->getConnection();
        if ($form->query()->getModel()->getConnection()->getName() !== $connection->getName()) {
            throw ValidationException::withMessages(['record' => 'Form and root must use the same connection.']);
        }
        $connection->beginTransaction();
        try {
            if (! $creating) {
                $locked = (clone $form->query())->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                $record->setRawAttributes($locked->getAttributes(), true);
                $record->unsetRelations();
            }
            $context = $creating ? null : $record;
            $form->forRecord($context)->initializeForSave();
            $feedback = $form->fire('submitted', $raw);
            if ($feedback instanceof Feedback) {
                $connection->rollBack();

                return ['cancelled' => true, 'feedback' => $feedback->toArray() ?: (object) []];
            }
            $form->assertSaveUrls();
            $planner = new RelationPlanner;
            $before = $planner->plan($form, $record, $raw, $context);
            $values = $before->values();
            $archiveForm = clone $form;
            $archiveForm->forRecord($creating ? null : clone $record);
            $identity = $record->getKey();
            $feedback = $form->fireSaving($record, $values);
            if ($feedback instanceof Feedback) {
                $connection->rollBack();

                return ['cancelled' => true, 'feedback' => $feedback->toArray() ?: (object) []];
            }
            if ($record->getKey() !== $identity || $record->getConnection()->getName() !== $connection->getName()) {
                throw ValidationException::withMessages(['record' => 'Saving hooks cannot change root identity or connection.']);
            }
            $plan = $planner->plan($form, $record, $values, $context)->withOriginalInventory($before->inventory()['original']);
            $values = $plan->values();
            // Complete graph validation precedes one whole-graph upload preflight.
            app(UploadArchiver::class)->archivePlan($archiveForm, $record, $values, $plan);
            (new RelationSynchronizer)->save($plan->withArchivedValues($values));
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
