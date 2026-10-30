<?php

namespace Brume\Form\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class Password extends Field
{
    public function fill(Model $record, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if ($this->writer !== null) {
            parent::fill($record, $value);

            return;
        }
        parent::fill($record, $record->hasCast($this->attributeName(), 'hashed') ? $value : Hash::make($value));
    }

    public function type(): string
    {
        return 'password';
    }

    public function valueFor(?Model $record): mixed
    {
        return '';
    }

    public function toSchema(?Model $record = null): array
    {
        return parent::toSchema($record) + ($record !== null ? ['help' => __('brume::form.password_keep')] : []);
    }
}
