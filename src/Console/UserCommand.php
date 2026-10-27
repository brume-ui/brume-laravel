<?php

namespace Brume\Console;

use Brume\Console\Concerns\ManagesAdmins;
use Illuminate\Console\Command;

class UserCommand extends Command
{
    use ManagesAdmins;

    protected $signature = 'brume:user
        {username : 登入用的帳號}
        {--name= : 顯示名稱，預設和帳號相同}
        {--password= : 密碼（至少 8 碼）；不填就互動輸入}';

    protected $description = '建立 Brume 管理員';

    public function handle(): int
    {
        $username = $this->argument('username');
        $model = $this->userModel();

        if ($model::query()->where('username', $username)->exists()) {
            $this->message('error', "帳號「{$username}」已經存在。請換一個帳號，或用 php artisan brume:password {$username} 重設它的密碼。");

            return self::FAILURE;
        }

        $password = $this->option('password');

        if ($password !== null && ($error = $this->passwordError($password)) !== null) {
            $this->message('error', $error);

            return self::FAILURE;
        }

        $model::query()->create([
            'username' => $username,
            'name' => $this->option('name') ?? $username,
            'password' => $password ?? $this->askNewPassword('密碼（至少 8 碼）'),
        ]);

        $this->message('info', "已建立管理員「{$username}」。");

        return self::SUCCESS;
    }
}
