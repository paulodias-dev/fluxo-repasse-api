# Macroentrega 04 — Gestão administrativa e RBAC técnico

## Objetivo e limites

Entregar capacidade administrativa mínima de contas para habilitar fluxos operacionais futuros, sem presumir o modelo financeiro do Fluxo Repasse. O modelo não define agências, empresas, tenants, comissões, beneficiários nem fórmulas de repasse.

## Modelo de dados

- `roles`: `id`, `slug` único, `name` e timestamps.
- `permissions`: `id`, `key` único, `description` e timestamps.
- `role_user`: relação de múltiplos papéis por usuário, composta por `role_id` e `user_id`.
- `permission_role`: relação de múltiplas permissões por papel.
- `auth_security_events.actor_user_id`: operador identificado por chave estrangeira anulável.

Até aprovação de outras regras existe apenas a permissão `users.manage`, concedida ao papel técnico `platform_admin` mediante comando explícito. Nenhum seeder cria usuários ou concede esse papel.

## Política de autorização

Todas as rotas de `/api/v1/admin` exigem token Bearer assinado, conta ativa, claim `av` atualizada e `users.manage`. O middleware de autorização consulta as relações no banco, impedindo acesso com JWT de usuário comum. O frontend esconde o módulo para não autorizados como UX complementar.

## Operações

- `GET /api/v1/admin/users?search=&status=all|active|inactive&page=1`: paginação fixa de 15, filtros validados, exposição mínima dos campos.
- `POST /api/v1/admin/users`: nome, e-mail, senha forte e confirmação; conta sem papéis.
- `PATCH /api/v1/admin/users/{user}`: somente nome/e-mail; payloads de role ou permission nunca concedem privilégio.
- `PATCH /api/v1/admin/users/{user}/status`: booleano is_active, revogação por auth_version; proíbe auto-desativação e desativação de conta administradora.

Os endpoints não permitem apagar contas, modificar senhas alheias, delegar papéis nem executar transações financeiras.

## Comandos operacionais

```bash
php artisan repasse:user:create
php artisan repasse:access:bootstrap operador@dominio.com
php artisan repasse:user:status email@dominio.com ativo
php artisan repasse:user:status email@dominio.com inativo
```

Usar endereços verdadeiros do ambiente. O bootstrap exige confirmação e invalida JWTs anteriores. O CLI impede desativar o último admin ativo.

## Auditoria e QA

Eventos incluem `account_created`, `account_updated`, `account_activated`, `account_deactivated` e `platform_admin_granted`; identificadores de operador e alvo são persistidos, nunca senha, token ou IP bruto. Ver `docs/QA.md` e `tests/Feature/AdminUsersTest.php`.

## Pendências

Gerar `composer.lock`, executar as migrations e testes em MySQL e homologar com React PWA. Definir posteriormente papéis de negócio, isolamento, delegação, autenticação reforçada e política LGPD conforme requisitos aprovados.
