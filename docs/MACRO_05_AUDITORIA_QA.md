# Macroentrega 05 — Auditoria administrativa e QA integrado

## Objetivo
Permitir que administradores técnicos examinem eventos de uma conta com trilha mínima, ao mesmo tempo protegendo o ciclo de identidade contra sessões antigas após troca de e-mail. Nenhuma função financeira foi criada.

## Contrato e controle de acesso
`GET /api/v1/admin/users/{user}/events?type=&from=&to=&page=1`

Requer JWT Bearer válido, `auth_version` atual, conta ativa e permissão `users.manage`. Resposta inclui `user_id` e `events` paginado (15). Cada evento contém exclusivamente: `id`, `event_type`, `actor_user_id`, `created_at`.

Filtros: `type` um dos tipos suportados; `from/to` no formato YYYY-MM-DD e intervalo cronológico; `page` de 1 a 10000. Nunca devolver `ip_hash`, senha, e-mail informado em login malsucedido, JWT ou corpo de requisição. Respostas `Cache-Control: no-store`. Ausência de permissão retorna 403.

## Revogação após troca de e-mail
A edição de nome e e-mail usa os campos explicitamente validados. Mudança de e-mail incrementa `auth_version`, invalida todos os JWTs anteriores e grava `account_email_changed`. Se só nome mudou, mantém a sessão e grava `account_updated`. Se não houve alteração, não cria registro redundante.

Campos `roles`, `permissions`, `auth_version`, `is_active` ou `password` não são aceitos por endpoints inadequados e devolvem 422. Privilégios técnicos permanecem apenas nos comandos Artisan autorizados.

## Verificação
Em ambiente adequado:
```bash
bash scripts/qa-preflight.sh
composer test
composer lint
php artisan route:list --path=api
```

No frontend:
```bash
npm run qa:smoke:test
QA_API_BASE_URL=http://127.0.0.1:8000 npm run qa:smoke
```
Sem credenciais, o smoke cobre só endpoints públicos/protegidos sem token. Para cobertura autenticada, definir `QA_USER_EMAIL`, `QA_USER_PASSWORD` e `QA_REQUIRE_AUTH=1` no ambiente de QA. O login/logout grava eventos; não altera usuários.

## Aceite ainda não realizado
Composer e extensões MySQL não estão disponíveis aqui; `composer.lock` e `package-lock.json` estão ausentes. É obrigatório instalar dependências legítimas, executar suites e testar em MySQL e navegadores reais. Não permitir merge como equivalente a homologação.

## Limites
Auditoria trata eventos de identidade, não livro-razão financeiro. Retenção, níveis de acesso mais granulares, exportação e política LGPD precisam de validação específica.
