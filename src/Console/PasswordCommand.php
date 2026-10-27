<?php

namespace Brume\Console;

use Brume\Console\Concerns\ManagesAdmins;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PasswordCommand extends Command
{
    use ManagesAdmins;

    protected $signature = 'brume:password {username : 要重設密碼的帳號}';

    protected $description = '重設 Brume 管理員的密碼';

    public function handle(): int
    {
        $username = $this->argument('username');
        $user = $this->userModel()::query()->where('username', $username)->first();

        if ($user === null) {
            $this->message('error', "找不到帳號「{$username}」。請確認拼字，或用 php artisan brume:user {$username} 建立這個管理員。");

            return self::FAILURE;
        }

        // A new remember token signs out every "remember me" cookie issued with the old password.
        $user->forceFill([
            'password' => $this->askNewPassword('新密碼（至少 8 碼）'),
            'remember_token' => Str::random(60),
        ])->save();

        $this->message('info', "已更新「{$username}」的密碼。");

        return self::SUCCESS;
    }
}
