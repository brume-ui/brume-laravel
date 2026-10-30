<?php

namespace Brume\Form;

use Illuminate\Database\Eloquent\Model;

/** Task 19 replaces this boundary with transactional upload archival. */
class UploadArchiver
{
    public function archive(Form $form, Model $record, array &$values): void {}
}
