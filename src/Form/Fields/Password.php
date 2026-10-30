<?php

namespace Brume\Form\Fields;

use Illuminate\Database\Eloquent\Model;

class Password extends Field
{
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
