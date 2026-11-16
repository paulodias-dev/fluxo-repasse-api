# Macroentrega 22 — Recuperação de acesso aos módulos

## Incidente observado
Na captura de /dashboard, a conta do administrador tem apenas 1 permissão e só exibe Usuários. O console bootstrap original garantia users.manage, e os seeders das novas macros não são executados automaticamente em atualizações de código. Além disso, o frontend colocava a tela de boas-vindas acima de cada módulo.

## Macro de correção da API
- Catálogo técnico único com 7 permissões: users.manage, parties.manage, roles.manage, cases.manage, scenarios.manage, scenario_reviews.manage e packages.manage. Não existem privilégios bancários.
- AdminUserSeeder idempotente sincroniza todas as permissões com o papel platform_admin, sem criar ou promover contas; ao modificar o papel, invalida JWTs existentes de seus membros via auth_version.
- Migration 2026_11_16_000008_backfill_existing_platform_admin_modules.php sincroniza automaticamente o papel platform_admin JÁ EXISTENTE ao aplicar migrations. Sem conta/papel novos, sem promoção de operadores comuns e sem ações destrutivas.
- O comando repasse:access:bootstrap foi corrigido: com confirmação explícita, vincula usuário existente/ativo ao catálogo técnico completo. Não define senhas e não libera dinheiro.
- Novo comando repasse:access:doctor confere papéis, grants e módulos acessíveis; --repair exige que a conta já possua platform_admin, confirmação explícita e só sincroniza esse papel.
- GET /api/v1/auth/me agora inclui roles e access_diagnostic; GET /api/v1/auth/access consulta o diagnóstico da própria sessão, sem conceder privilégios.
- OpenAPI 1.5, testes de segurança, revogação de JWT, migração idempotente e ausência de elevação indevida.

## Passos no ambiente local após merge e backup
No diretório da API:

    php artisan migrate
    php artisan repasse:access:doctor usuario@dominio.com

Se platform_admin = SIM e há 7 permissões, basta logout/login. Se continuar incompleto, após autorização:

    php artisan repasse:access:doctor usuario@dominio.com --repair

Se platform_admin = NÃO, nunca promover automaticamente. Só quando o responsável autorizar:

    php artisan repasse:access:bootstrap usuario@dominio.com

Com a versão antiga da API e papel platform_admin já atribuído, o comando imediato de correção é:

    php artisan db:seed --class=AdminUserSeeder

Qualquer sincronização que conceda novos grants a membros do papel técnico revoga JWTs antigos. Nenhuma dessas operações muda a política de pagamento e nenhuma habilita execução financeira.

## QA focalizado pendente
Executar php artisan test --filter=OperationalAccessRecoveryTest, migrations reais em MySQL, Pint focalizado e smoke da sessão. A sessão atual não dispõe do MySQL do usuário, portanto o papel real dessa conta não pôde ser inspecionado e esses comandos não foram executados.
