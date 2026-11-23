<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Tenant;
use App\Support\TenantRoles;
use App\Services\AuthEventRecorder;
use App\Services\FinanceCategoryCatalog;
use App\Services\VehicleCatalogSyncService;
use App\Support\OperationalAccessCatalog;
use App\Support\OperationalAcceptance;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

Artisan::command('repasse:vehicle-catalog:sync {--type=all : cars, motorcycles, trucks ou all} {--brand= : Código externo da marca, opcional} {--with-prices : Também consultar e armazenar preços FIPE}', function (): int {
    $type = strtolower(trim((string) $this->option('type')));
    $allowed = ['cars', 'motorcycles', 'trucks'];
    $types = $type === 'all' ? $allowed : [$type];
    if (! in_array($type, array_merge(['all'], $allowed), true)) {
        $this->error('Informe --type=cars, motorcycles, trucks ou all.');
        return Command::FAILURE;
    }

    $brand = trim((string) ($this->option('brand') ?? '')) ?: null;
    $withPrices = (bool) $this->option('with-prices');
    if ($withPrices) {
        $this->warn('A carga de preços faz uma consulta por combinação modelo/ano e pode consumir muitas requisições.');
    }

    $failed = false;
    foreach ($types as $vehicleType) {
        $this->info('Sincronizando '.$vehicleType.($brand ? ' / marca '.$brand : '').'…');
        try {
            $stats = app(VehicleCatalogSyncService::class)->sync($vehicleType, $brand, $withPrices);
            $this->line(sprintf('  marcas: %d | modelos: %d | anos: %d | preços: %d',
                $stats['brands'], $stats['models'], $stats['years'], $stats['prices']));
        } catch (Throwable $exception) {
            $failed = true;
            $this->error('Falha em '.$vehicleType.': '.$exception->getMessage());
        }
    }

    return $failed ? Command::FAILURE : Command::SUCCESS;
})->purpose('Sincronizar o catálogo global de veículos para o MySQL sem dados de tenant');

Artisan::command('repasse:tenant:create {slug : Identificador único da empresa} {name : Razão/nome da empresa} {email : E-mail inicial do administrador}', function (): int {
    $slug = Str::lower(trim((string) $this->argument('slug')));
    $name = trim((string) $this->argument('name'));
    $email = Str::lower(trim((string) $this->argument('email')));
    $adminName = trim((string) $this->ask('Nome do administrador'));
    $password = (string) $this->secret('Senha inicial (mín. 12, maiúscula/minúscula/número/símbolo)');

    $validator = Validator::make(compact('slug', 'name', 'email', 'password', 'adminName'), [
        'slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{2,99}$/', 'unique:tenants,slug'],
        'name' => ['required', 'string', 'min:2', 'max:180'],
        'adminName' => ['required', 'string', 'min:2', 'max:255'],
        'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')],
        'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) $this->error($error);
        return Command::FAILURE;
    }
    app(AdminUserSeeder::class)->run();

    DB::transaction(function () use ($slug, $name, $email, $adminName, $password): void {
        $tenant = Tenant::query()->create(['slug' => $slug, 'name' => $name, 'is_active' => true]);
        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $adminName,
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
        ]);
        $roles = TenantRoles::provision((int) $tenant->id);
        $admin->roles()->sync([$roles['tenant_admin']->id]);
        app(FinanceCategoryCatalog::class)->ensureForTenant((int) $tenant->id, (int) $admin->id);
    });

    $this->info('Empresa e primeiro administrador criados. Nenhum dado de outra empresa foi compartilhado.');
    return Command::SUCCESS;
})->purpose('Provisionar uma empresa isolada e seu administrador inicial, sem senhas em argumentos');

Artisan::command('repasse:tenant:seed', function (): int {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('Este comando de teste só pode ser executado em local ou testing.');

        return Command::FAILURE;
    }

    $slug = 'fluxo-repasse';
    $name = 'Fluxo Repasse';
    $email = 'admin.repasse@fluxosistemas.com.br';
    $adminName = 'Administrador do Repasse';
    $password = 'Tki&rn3233';

    app(AdminUserSeeder::class)->run();

    try {
        DB::transaction(function () use ($slug, $name, $email, $adminName, $password): void {
            $tenant = Tenant::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'is_active' => true]
            );

            $admin = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($admin && (int) $admin->tenant_id !== (int) $tenant->id) {
                throw new RuntimeException('O e-mail informado já pertence a outra empresa.');
            }

            if (! $admin) {
                $admin = User::query()->create([
                    'tenant_id' => $tenant->id,
                    'name' => $adminName,
                    'email' => $email,
                    'password' => $password,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);
            } else {
                $passwordChanged = ! Hash::check($password, (string) $admin->getRawOriginal('password'));
                $statusChanged = ! $admin->is_active;
                $admin->name = $adminName;
                $admin->is_active = true;
                $admin->email_verified_at = now();
                if ($passwordChanged || $statusChanged) {
                    $admin->password = $password;
                    $admin->auth_version = (int) $admin->auth_version + 1;
                }
                $admin->save();
            }

            $roles = TenantRoles::provision((int) $tenant->id);
            $admin->roles()->syncWithoutDetaching([$roles['tenant_admin']->id]);
            app(FinanceCategoryCatalog::class)->ensureForTenant((int) $tenant->id, (int) $admin->id);
        });
    } catch (RuntimeException $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $this->info('Tenant Fluxo Repasse e administrador inicial prontos para teste.');
    $this->line('Usuário: '.$email);
    $this->warn('Comando exclusivo para desenvolvimento/testes; não use esta credencial em produção.');

    return Command::SUCCESS;
})->purpose('Criar ou atualizar o tenant Fluxo Repasse e seu administrador de teste');

Artisan::command('repasse:user:create', function (): int {
    $slug = Str::lower(trim((string) $this->ask('Slug da empresa existente')));
    $tenant = Tenant::query()->where('slug', $slug)->where('is_active', true)->first();
    if (! $tenant) {
        $this->error('Empresa não localizada ou desativada.');
        return Command::FAILURE;
    }
    $name = trim((string) $this->ask('Nome completo'));
    $email = Str::lower(trim((string) $this->ask('E-mail')));
    $password = (string) $this->secret('Senha (mínimo 12 caracteres, maiúscula, minúscula, número e símbolo)');

    $data = ['name' => $name, 'email' => $email, 'password' => $password, 'email_verified_at' => now()];
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

    DB::transaction(function () use ($data, $tenant): void {
        $user = User::query()->create([...$data, 'tenant_id' => $tenant->id]);
        $roles = TenantRoles::provision((int) $tenant->id);
        $user->roles()->sync([$roles['tenant_user']->id]);
    });
    $this->info('Usuário funcional da empresa criado. Sem acesso a outras empresas.');

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

    try {
        DB::transaction(function () use ($user, $active): void {
            $adminRole = Role::query()->where('slug', 'platform_admin')->lockForUpdate()->first();
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $active && $adminRole && $locked->roles()->whereKey($adminRole->getKey())->exists()) {
                $activeAdmins = User::query()->where('is_active', true)->whereHas('roles',
                    fn ($query) => $query->whereKey($adminRole->getKey())
                )->count();

                if ($activeAdmins <= 1) {
                    throw new RuntimeException('Não é permitido desativar o último administrador ativo.');
                }
            }
            $locked->is_active = $active;
            $locked->auth_version = (int) $locked->auth_version + 1;
            $locked->save();

            app(AuthEventRecorder::class)->record(
                $active ? 'account_activated' : 'account_deactivated',
                null,
                $locked
            );
        });
    } catch (RuntimeException $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $this->info('Status atualizado. Todas as sessões anteriores foram invalidadas.');

    return Command::SUCCESS;
})->purpose('Ativar ou desativar uma conta e revogar todos os JWTs emitidos');

Artisan::command('repasse:access:bootstrap {email : Conta existente que será administradora} {--yes : Confirmar sem prompt}', function (): int {
    $email = Str::lower(trim((string) $this->argument('email')));
    $user = User::query()->where('email', $email)->first();

    if (! $user || ! $user->is_active) {
        $this->error('A conta precisa existir e estar ativa.');
        return Command::FAILURE;
    }

    $total = count(OperationalAccessCatalog::TECHNICAL_PERMISSIONS);
    $this->warn("Acesso técnico administrativo: {$total} permissões (usuários, cadastros, RBAC, dossiês, cenários, pareceres e pacotes).");
    $this->line('Não será concedida nenhuma permissão de pagamento, liquidação ou operação bancária.');

    if (! $this->option('yes')
        && ! $this->confirm('Confirmar vínculo da conta existente ao papel platform_admin?', false)) {
        return Command::FAILURE;
    }

    DB::transaction(function () use ($user): void {
        // Keep permissions current BEFORE assigning the explicitly approved user.
        app(AdminUserSeeder::class)->run();
        $role = Role::query()->where('slug', 'platform_admin')->firstOrFail();
        $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
        if (! $locked->is_active) throw new RuntimeException('Usuário desativado durante a operação.');
        if (! $locked->roles()->whereKey($role->getKey())->exists()) {
            $locked->roles()->syncWithoutDetaching([$role->getKey()]);
            $locked->auth_version = (int) $locked->auth_version + 1;
            $locked->save();
            app(AuthEventRecorder::class)->record('platform_admin_granted', null, $locked);
        }
    });

    $this->info('Acesso administrativo técnico sincronizado. Saia do painel e faça novo login.');
    return Command::SUCCESS;
})->purpose('Vincular usuário existente ao papel técnico com catálogo completo e consentimento explícito');

Artisan::command('repasse:access:doctor {email : Conta a conferir} {--repair : Sincronizar somente o papel platform_admin} {--yes : Confirmar sem prompt} {--json : Mostrar diagnóstico em JSON}', function (): int {
    $email = Str::lower(trim((string) $this->argument('email')));
    $user = User::query()->where('email', $email)->first();
    if (! $user) {
        $this->error('Conta não localizada: nenhuma alteração efetuada.');
        return Command::FAILURE;
    }

    $role = $user->roles()->where('slug', 'platform_admin')->exists();
    $before = $user->grantedPermissions();
    if ($this->option('repair')) {
        if (! $role) {
            $this->error('Reparo não permitido: esta conta NÃO possui platform_admin.');
            $this->line('O vínculo administrativo exige autorização explícita pelo comando repasse:access:bootstrap <email>.');
            return Command::FAILURE;
        }
        $missing = OperationalAccessCatalog::missingForAdmin($before);
        if ($missing !== []) {
            $this->warn('O reparo sincroniza o catálogo para TODOS os membros do papel platform_admin e invalida seus JWTs.');
            if (! $this->option('yes')
                && ! $this->confirm('Confirmar sincronização dos privilégios administrativos existentes?', false)) {
                return Command::FAILURE;
            }
            app(AdminUserSeeder::class)->run();
        }
    }

    $user->refresh();
    $current = $user->grantedPermissions();
    $result = [
        'user_id' => (int) $user->id, 'email' => $user->email,
        'is_active' => (bool) $user->is_active, 'platform_admin' => $role,
        'roles' => $user->roles()->pluck('slug')->all(),
        'granted_permissions' => $current,
        'missing_platform_admin_permissions' => $role
            ? OperationalAccessCatalog::missingForAdmin($current) : null,
        'modules' => OperationalAccessCatalog::modulesFor($current),
        'next_action' => ! $role ? 'Solicitar autorização do bootstrap administrativo'
            : (OperationalAccessCatalog::missingForAdmin($current) !== []
                ? 'Executar repasse:access:doctor <email> --repair após autorização'
                : 'Permissões atuais: atualizar sessão por novo login'),
    ];
    if ($this->option('json')) {
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    } else {
        $this->info('Conta: '.$result['email'].' · platform_admin: '.($role ? 'SIM' : 'NÃO'));
        $this->line('Permissões: '.implode(', ', $current));
        $this->line('Módulos acessíveis: '.implode(', ', array_column(
            array_filter($result['modules'], static fn (array $m): bool => $m['available']), 'name'
        )));
        $this->line($result['next_action']);
    }

    return Command::SUCCESS;
})->purpose('Diagnosticar menus ausentes e reparar apenas permissões do papel admin já atribuído');

Artisan::command('repasse:access:revoke-admin {email : Usuário que perderá a permissão técnica} {--yes : Confirmar sem prompt}', function (): int {
    $email = Str::lower(trim((string) $this->argument('email')));
    $user = User::query()->where('email', $email)->first();

    if (! $user) {
        $this->error('Usuário não encontrado.');

        return Command::FAILURE;
    }

    $role = Role::query()->where('slug', 'platform_admin')->first();

    if (! $role || ! $user->roles()->whereKey($role->getKey())->exists()) {
        $this->info('Esta conta não possui o papel administrativo.');

        return Command::SUCCESS;
    }

    if (! $this->option('yes') && ! $this->confirm('Revogar users.manage e todas as sessões atuais?', false)) {
        return Command::FAILURE;
    }

    try {
        DB::transaction(function () use ($user): void {
            $role = Role::query()->where('slug', 'platform_admin')->lockForUpdate()->firstOrFail();
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->roles()->whereKey($role->getKey())->exists()) {
                return;
            }

            $activeAdmins = User::query()->where('is_active', true)->whereHas('roles',
                fn ($query) => $query->whereKey($role->getKey())
            )->count();

            if ($locked->is_active && $activeAdmins <= 1) {
                throw new RuntimeException('Não é permitido revogar o último administrador ativo.');
            }

            $locked->roles()->detach($role->getKey());
            $locked->auth_version = (int) $locked->auth_version + 1;
            $locked->save();
            app(AuthEventRecorder::class)->record('platform_admin_revoked', null, $locked);
        });
    } catch (RuntimeException $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $this->info('Permissão técnica revogada; tokens anteriores inválidos.');

    return Command::SUCCESS;
})->purpose('Revogar administração técnica sem permitir remover o último admin ativo');


// Structural acceptance is deliberately read-only. A broken installation must
// be repaired explicitly (migrations/seeders/bootstrap), never by this command.
// --json is safe for CI logs: no emails, tokens, passwords, paths or PII.
Artisan::command('repasse:acceptance {--json : Imprimir relatório sem dados pessoais}', function (): int {
    $result = app(OperationalAcceptance::class)->inspect();
    if ($this->option('json')) {
        $this->line(json_encode(
            $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
    } else {
        $this->info('Diagnóstico estrutural de módulos (somente leitura)');
        foreach ($result['checks'] as $check) {
            $this->line(($check['ok'] ? '[OK] ' : '[FALHA] ').$check['id']);
        }
        $this->line($result['ready']
            ? 'Estrutura pronta para executar testes integrados de homologação.'
            : 'Estrutura incompleta. Verifique migrations, catálogo de papéis e administrador.');
        $this->warn('Não é prova de build React, de pagamento ou de testes executados.');
    }

    return $result['ready'] ? Command::SUCCESS : Command::FAILURE;
})->purpose('Diagnosticar módulos/perm. sem mutar banco ou mostrar segredos');
