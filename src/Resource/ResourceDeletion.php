<?php

namespace Brume\Resource;

use Brume\Form\Form;
use Brume\Grid\Actions\Feedback;
use Brume\Uploads\StoredUploadCleanup;
use Closure;
use Illuminate\Support\Collection;

/** Runs resource hooks atomically; model event vetoes still skip only their row. */
final class ResourceDeletion
{
    public function run(Collection $models, Closure $forms): int|Feedback
    {
        if ($models->isEmpty()) {
            return 0;
        }
        $connection = $models->first()->getConnection();
        $connection->beginTransaction();
        try {
            $deleted = 0;
            foreach ($models as $record) {
                /** @var Form $form */
                $form = $forms($record);
                // Match edit/upload/save declarations before inventorying persisted paths.
                $form->initialize();
                $cleanup = app(StoredUploadCleanup::class);
                $files = $cleanup->capture($form, $record);
                $feedback = $form->fire('deleting', $record);
                if ($feedback instanceof Feedback) {
                    $connection->rollBack();

                    return $feedback;
                }
                if ($record->delete() === false) {
                    continue;
                }
                $form->fire('deleted', $record);
                $cleanup->afterHardDelete($record, $files);
                $deleted++;
            }
            $connection->commit();

            return $deleted;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
