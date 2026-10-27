<?php

namespace Brume\Console\Concerns;

use Brume\Models\User;

use function Laravel\Prompts\password;

/**
 * Shared by brume:install, brume:user and brume:password so every way of setting a password
 * applies the same rule and the same wording.
 */
trait ManagesAdmins
{
    use WritesMessages;

    protected const MIN_PASSWORD_LENGTH = 8;

    protected function passwordError(string $password): ?string
    {
        return mb_strlen($password) < self::MIN_PASSWORD_LENGTH
            ? '密碼至少 8 碼，請換一個更長的密碼。'
            : null;
    }

    /**
     * Ask twice until both entries match; each entry must pass passwordError().
     */
    protected function askNewPassword(string $label): string
    {
        while (true) {
            $password = password(
                label: $label,
                required: true,
                validate: fn (string $value) => $this->passwordError($value),
            );

            if (password(label: '再輸入一次密碼', required: true) === $password) {
                return $password;
            }

            $this->message('error', '兩次輸入的密碼不一樣，請重新輸入。');
        }
    }

    /** @return class-string<User> */
    protected function userModel(): string
    {
        return config('brume.auth.model');
    }
}
