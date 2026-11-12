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

Artisan::command('repasse:user:status {email : E-mail do usuário} {state : ativo ou inativo} {--yes : Confirmar sem prompt}', function (): int {
    $email = Str::lower(trim((string) $this->argument('email')));
    $state = (string) $this->argument('state');

    if (! in_array($state, ['ativo', 'inativo'], true)) {
        $this->error('Informe apenas ativo ou inativo.');
        return Command::FAILURE;
    }

    $user = User::query()->where('email', $email)->first();

    if (! $user) {
        $this->error('Usuário não encontrado.');
        return Command::FAILURE;
    }

    $active = $state === 'ativo';

    if ((bool) $user->is_active === $active) {
        $this->info('A conta já está no estado solicitado.');
        return Command::SUCCESS;
    }

    if (! $this->option('yes') && ! $this->confirm('Confirmar alteração de status e invalidação de todas as sessões?', false)) {
        return Command::FAILURE;
    }

    \Illuminate\Support\Facades\DB::transaction(function () use ($user, $active): void {
        $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
        $locked->is_active = $active;
        $locked->auth_version = (int) $locked->auth_version + 1;
        $locked->save();

        app(\App\Services\AuthEventRecorder::class)->record(
            $active ? 'account_activated' : 'account_deactivated',
            null,
            $locked
        );
    });

    $this->info('Status atualizado. Todas as sessões anteriores foram invalidadas.');
    return Command::SUCCESS;
})->purpose('Ativar ou desativar uma conta e revogar todos os JWTs emitidos');
