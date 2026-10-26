<?php

namespace App\Console\Commands;

use App\Actions\CreateUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {--email=} {--name=} {--password-stdin}';

    protected $description = 'Create an administrator within the ten-account limit';

    public function handle(CreateUser $create): int
    {
        $data = ['email' => $this->option('email') ?? $this->ask('Email'), 'name' => $this->option('name') ?? $this->ask('Display name'), 'password' => $this->option('password-stdin') ? rtrim(stream_get_contents(STDIN), "\r\n") : $this->secret('Password (12+ characters, letters and numbers)'), 'is_admin' => true];
        $data['email'] = mb_strtolower(trim((string) $data['email']));
        Validator::make($data, ['email' => ['required', 'email', 'max:255', 'unique:users,email'], 'name' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', Password::min(12)->letters()->numbers(), 'max:128']])->validate();
        $user = $create->handle($data);
        $this->info('Administrator created: '.$user->email);

        return self::SUCCESS;
    }
}
