<?php

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

Artisan::command('repasse:user:create', function (): int {
    $name = trim((string) $this->ask('Nome completo'));
    $email = Str::lower(trim((string) $this->ask('E-mail')));
    $password = (string) $this->secret('Senha (mínimo 12 caracteres, maiúscula, minúscula, número e símbolo)');

    $data = ['name' => $name, 'email' => $email, 'password' => $password];
    $validator = Validator::make($data, [
        'name' => ['required', 'string', 'min:2', 'max:255'],
        'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')],
        'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return Command::FAILURE;
    }

    User::query()->create($data);
    $this->info('Usuário criado. Nenhuma permissão financeira foi concedida.');

    return Command::SUCCESS;
})->purpose('Criar usuário inicial sem credenciais fixas ou cadastro público');
