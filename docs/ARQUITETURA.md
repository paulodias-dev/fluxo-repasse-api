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
