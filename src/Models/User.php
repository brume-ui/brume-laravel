<?php

namespace Brume\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = ['username', 'password', 'name', 'avatar'];

    protected $hidden = ['password', 'remember_token'];

    public function getTable(): string
    {
        return config('brume.auth.table', 'brume_users');
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
