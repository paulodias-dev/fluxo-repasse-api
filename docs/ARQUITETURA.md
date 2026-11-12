# Fluxo Repasse API — Arquitetura técnica (Macroentrega 03)

## Stack e separação
Laravel 12, PHP >=8.3, MySQL 8+, API versionada `/api/v1`; PWA React/Vite em repositório independente. Sem regras financeiras presumidas.

## Identidade
- `User`: senha hash, `is_active` e `auth_version` persistidos em MySQL.
- `JWTSubject`: claim numérica `av` representa a versão de autorização da sessão.
- `EnsureCurrentSession`: após `auth:api`, exige `is_active` e `av === auth_version`; JWT legado sem claim deve reautenticar.
- `config/jwt.php`: `persistent_claims=['av']` preserva versão nas rotações de token.
- `ChangePasswordRequest`: valida confirmação e senha forte; backend verifica hash da senha atual.
- `AuthController::changePassword`: bloqueio de linha `FOR UPDATE`, troca de senha, incremento de versão e auditoria na mesma transação.
- `repasse:user:status {email} {ativo|inativo}`: execução via console; confirmação e incremento de versão, com revogação global dos tokens anteriores.
- `AuthEventRecorder`: grava eventos de segurança (logins, logout, refresh, alteração de senha, estado da conta).

## Auditoria e minimização
`auth_security_events`: `user_id` opcional, `event_type`, `ip_hash` (HMAC-SHA256 com APP_KEY), `created_at`. Não grava senha, e-mail digitado em login malsucedido, JWT, payload ou IP em claro. Falhas de gravação interrompem a operação protegida (fail-closed).
A tabela é apêndice de eventos de identidade; não representa ainda o livro-razão financeiro nem uma trilha imutável externa. Política LGPD de retenção e exportação depende de aprovação.

## Contratos
- Público: GET `/health`, POST `/auth/login`.
- Privado (Bearer no cabeçalho + `auth:api` + `session.current`): GET `/auth/me`, POST `/auth/refresh`, POST `/auth/logout`, POST `/auth/password`.
- Não existe registro público nem endpoint de repasse.
- Contrato OpenAPI em `docs/openapi.yaml`. Documento estático: servidor de Swagger UI/L5-Swagger ainda não instalado.
- TLS, CORS restritivo, rate-limit, cache blacklist compartilhado e `APP_DEBUG=false` para produção.

## Migração de versões
Migrations são **aditivas**, sem `migrate:fresh`. Contas já existentes terão `is_active=true` e `auth_version=1`; tokens antigos sem `av` deixam de funcionar e requerem novo login. Antes do deploy, backup e ensaio de migrations em banco homólogo.

## Extensão de arquitetura — Macroentrega 04

- `roles`, `permissions`, `role_user` e `permission_role` compõem um RBAC extensível; por enquanto só `users.manage` é reconhecida como permissão técnica.
- `User::hasPermission()` consulta o banco a cada operação; a presença de um menu no frontend não concede autorização.
- `EnsurePermission` executa depois de `bearer.only`, `auth:api` e `session.current`; o padrão é negar (HTTP 403).
- `AdminUserController` disponibiliza operações de usuários, sem permitir alteração de papéis, senha de terceiros ou movimentações financeiras.
- `repasse:access:bootstrap` atribui `platform_admin` a usuário existente e ativo; `repasse:access:revoke-admin` o retira sem revogar o último admin ativo. Ambos exigem confirmação e auditoria.
- `auth_security_events.actor_user_id` identifica o operador em cadastros, edições e alterações de status.
- Todas as respostas de listagem são projetadas explicitamente com `id`, `name`, `email`, `is_active` e `created_at`; hashes, JWT e `auth_version` não são expostos.
- Desativação de usuário incrementa `auth_version`; JWTs antigos são recusados pelo middleware de sessão vigente.
- Multiempresa/tenant e papéis financeiros permanecem **não definidos**, sem migrações ou políticas presumidas.

## Macro 05 — Histórico de conta e integridade de sessão

`AdminUserAuditController` consulta a tabela existente `auth_security_events`, restringe `user_id` ao parâmetro com model binding e projeta apenas os campos `id`, `event_type`, `actor_user_id` e `created_at`. Utiliza `users.manage` na mesma cadeia `bearer.only → auth:api → session.current → permission:users.manage`, com limite de consultas por usuário e paginação de 15 registros.

Os filtros `type` (enum de eventos conhecidos), `from` e `to` (datas YYYY-MM-DD, intervalo válido) e `page` são validados antes da consulta. Não existe endpoint público de auditoria, exportação em massa nem acesso a hashes de IP.

`AdminUserController::update` aplica lock pessimista e transação. Ao alterar e-mail incrementa `auth_version`, revogando JWTs anteriores de todos os dispositivos, e registra `account_email_changed`. Alteração somente de nome gera `account_updated`; no-op não grava evento. O contrato não permite atribuir papéis, permissões ou senha por essa rota.

Como não houve inclusão de tabelas/colunas nesta macro, não há migration nova. Ainda é necessário aplicar as migrations anteriores em um banco MySQL de homologação limpo e executar testes.
