# Fluxo Repasse — API Laravel + MySQL

API REST do **Fluxo Repasse**, com identidade JWT, gestão básica de ciclo de contas e auditoria de segurança.

## Tecnologias
PHP 8.3+, Laravel 12, MySQL 8+, JWT (php-open-source-saver/jwt-auth), testes PHPUnit.

## Capacidades disponíveis
- `GET /api/v1/health`: disponibilidade básica.
- `POST /api/v1/auth/login`: autenticação, limitação de tentativas e login auditado.
- `GET /api/v1/auth/me`: perfil autenticado mínimo.
- `POST /api/v1/auth/refresh`: rotação manual do JWT válido.
- `POST /api/v1/auth/logout`: revogação individual.
- `POST /api/v1/auth/password`: troca da própria senha, revogação global dos JWTs anteriores.
- `php artisan repasse:user:create`: provisionamento interativo sem senha fixa.
- `php artisan repasse:user:status EMAIL ativo|inativo`: controle de conta via CLI, revogação global das sessões.
- Auditoria mínima de login, logout, refresh, credenciais e suspensão; sem senha, e-mail digitado ou IP em claro.

Nenhuma rota de repasse, valor financeiro, beneficiário ou cadastro público foi criada. Identidade **não equivale** a autorização financeira.

## Instalação
```bash
git clone https://github.com/paulodias-dev/fluxo-repasse-api.git
cd fluxo-repasse-api
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
# Criar o banco MySQL e configurar credenciais no .env
php artisan migrate
php artisan repasse:user:create
php artisan serve --host=127.0.0.1 --port=8000
```
Na atualização de ambiente existente, preservar APP_KEY/JWT_SECRET e executar migrations **aditivas**, após backup.

## QA e pré-homologação
```bash
bash scripts/qa-preflight.sh
composer test
composer lint
php artisan migrate:status
php artisan route:list --path=api
```
Sem `composer.lock`, Composer/dependências instaladas e testes MySQL reais **não existe homologação**. Gerar o lock e evidências em ambiente conectado antes de produção.

## Documentação
- [Instalação/migração](docs/INSTALACAO.md)
- [Arquitetura de identidade](docs/ARQUITETURA.md)
- [OpenAPI 0.3](docs/openapi.yaml)
- [QA](docs/QA.md)
- [Segurança/LGPD](docs/SEGURANCA.md)
- [Deploy](docs/DEPLOY.md)
- [Roadmap](docs/ROADMAP.md)
- [Requisitos funcionais pendentes](docs/REQUISITOS_PENDENTES.md)
- [AGENTS](AGENTS.md)

Frontend: [fluxo-repasse-app](https://github.com/paulodias-dev/fluxo-repasse-app).

> O histórico funcional do Gemini ainda não foi recuperado. Nenhum contrato financeiro foi inventado.

## Macroentrega 04 — Administração de usuários

A API passa a disponibilizar uma base de RBAC técnico, limitada à permissão `users.manage`. Não são definidos papéis financeiros ou organizações.

| Método | Endpoint | Função |
|---|---|---|
| GET | `/api/v1/admin/users` | Busca, filtro de status e paginação (15 registros) |
| POST | `/api/v1/admin/users` | Cadastro de usuário comum, sem papéis |
| PATCH | `/api/v1/admin/users/{user}` | Edição de nome e e-mail |
| PATCH | `/api/v1/admin/users/{user}/status` | Ativação/desativação e invalidação de sessões |

Todas as rotas exigem Bearer JWT válido, conta ativa e `users.manage` **validada no backend**.

Para criar o primeiro administrador técnico, após a instalação e as migrations:

```bash
php artisan repasse:user:create
php artisan repasse:access:bootstrap email@dominio.com
php artisan repasse:access:revoke-admin email@dominio.com
```

A segunda operação exige confirmação no terminal, é auditada e invalida sessões antigas. Nenhum usuário ou privilégio é criado automaticamente por seeders. A desativação do último administrador ativo é proibida; a API também impede desativação da própria conta e de contas administradoras.

Leia `docs/MACRO_04_USUARIOS_RBAC.md` para regras e contratos, e `docs/QA.md` para critérios de validação.

## Macroentrega 05 — Histórico e proteção de identidade

- `GET /api/v1/admin/users/{user}/events`: histórico paginado, filtros `type`, `from`, `to`, `page`, autorização `users.manage`.
- Respostas de auditoria incluem somente `id`, `event_type`, `actor_user_id` e `created_at`; sem hash de IP, senhas, token ou payload.
- Troca de e-mail de usuário incrementa `auth_version`, invalida sessões anteriores e grava evento `account_email_changed`.
- Alteração somente de nome não revoga JWT; edição sem mudança não cria auditoria duplicada.
- `StoreUserRequest`, `UpdateUserRequest` e `SetUserStatusRequest` rejeitam explicitamente campos de papéis, permissões, senha ou estado fora do endpoint permitido.
- Testes PHPUnit adicionais documentam autorização, filtros, LGPD, revogação de sessões e negação de campos privilegiados.
- OpenAPI 0.5: `docs/openapi.yaml`.

**QA:** consulte `docs/MACRO_05_AUDITORIA_QA.md`. O APP contém smoke test com Node 20.19+/22.12+ sem pacotes adicionais. Ainda não foram executados Composer, migrations ou PHPUnit neste ambiente; `composer.lock` permanece ausente.

## Macroentrega 06 — Health separado de readiness

`GET /api/v1/health` confirma apenas a resposta HTTP. O novo `GET /api/v1/ready` verifica a conexão MySQL e sete tabelas essenciais; responde 200/ready ou 503/unavailable sem vazar detalhes operacionais. A especificação OpenAPI foi atualizada para 0.6.

Os arquivos novos passaram em `php -l` com PHP 8.4.24. Foram escritos quatro testes Laravel, ainda **não executados** por ausência de Composer/MySQL. Os oito testes de contrato do smoke do frontend passaram localmente com Node 22.16.0, mas usam API simulada.

**Percentual global registrado:** 41,67% dos 12 macromarcos integrados na main; 50,00% dos marcos com a macro 06 nos PRs #6, ainda pendentes de revisão. Macro 06: 12/16 checkpoints = 75,00%. O percentual de conclusão funcional de repasses não é aferível sem o escopo Gemini. Documentos: `docs/PROGRESSO_GERAL.md` e `docs/MACRO_06_READINESS_QA.md`.

## Macroentrega 07 — QA reproduzível em ambiente isolado

O repositório agora contém `compose.qa.yaml`, `qa/Dockerfile.api`, `qa/run.sh` e `qa/selftest.sh`. O QA integra MySQL 8.4 privado, PHP 8.4/Composer e Node 22 com o clone React, sem GitHub Actions e sem remoção automática de volumes.

```bash
bash qa/selftest.sh                 # 6/6 bloqueios testados localmente
QA_LEVEL=public bash qa/run.sh     # exige os dois lockfiles e segredos de QA
```

O runner rejeita `composer.lock` ou `package-lock.json` ausentes, banco fora de `*_qa` e chaves não configuradas. Detalhes de segurança e provisionamento em [qa/README.md](qa/README.md).

**QA executado nesta entrega:** seis testes de bloqueio shell, parse do Compose YAML, sintaxe bash; no APP, nove testes reais de integridade npm. **Ainda não executado:** Docker, Composer, PHPUnit, migrations MySQL, npm ci, Vitest ou build. O `package-lock.json` do APP já estava versionado na `main` e foi auditado nesta entrega (npm v3, 525 entradas, checksums e manifesto coerentes). O `composer.lock` da API segue ausente; nenhum arquivo foi fabricado.

**Percentual macro 07:** 11/14 = 78,57%. **Percentual total do roadmap provisório:** 6/12 = 50,00% (marco 07 ainda incompleto). [Medição e evidências](docs/PROGRESSO_GERAL.md).
