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

**QA executado nesta entrega:** seis testes de bloqueio shell, parse do Compose YAML, sintaxe bash; no APP, nove testes reais de integridade npm. **Ainda não executado:** Docker, Composer, PHPUnit, migrations MySQL, npm ci, Vitest ou build. O `package-lock.json` do APP já estava versionado na `main` e foi auditado nesta entrega (npm v3, 525 entradas, checksums e manifesto coerentes). O `composer.lock` da API também está na main (113 pacotes inspecionados); nenhum lock foi fabricado.

**Percentual macro 07:** 11/14 = 78,57%. **Percentual total do roadmap provisório:** 6/12 = 50,00% (marco 07 ainda incompleto). [Medição e evidências](docs/PROGRESSO_GERAL.md).

## Macroentrega 08 — QA MySQL seguro e RBAC negativo

A suíte `phpunit.mysql.xml` substitui explicitamente o SQLite **somente no Compose de QA**. O bootstrap `qa/phpunit-mysql-bootstrap.php` bloqueia banco/host/driver/ambiente indevidos antes de carregar Laravel. O runner executa testes contra MySQL e aguarda `GET /api/v1/ready` antes do smoke HTTP.

A regressão de teste de auditoria `account_email_changed` foi corrigida. `QA_LEVEL=full` exige duas contas diferentes: administrador e usuário comum sem `users.manage`. O APP testa ambas as rotas administrativas com resposta 403 para a conta comum.

**Testes efetivamente executados nesta entrega:** 10 PHP + 10 Shell + 15 Node = **35/35**, com mocks e validação de gates, sem bancos reais. Não foram executados PHPUnit/Pint/MySQL, npm ci/Vitest/build ou PWA em navegador.

O `composer.lock` já está versionado. Em ambiente com Composer, executar `composer validate --strict` e `composer install`; `qa/resolve-composer-lock.sh` fica disponível apenas como opção para um clone que não tenha lockfile. O lock já existia na main e não foi alterado nesta entrega.

Detalhes: `docs/MACRO_08_QA_MYSQL_RBAC.md`, `qa/README.md` e `docs/PROGRESSO_GERAL.md`.

**Macro 08: 15/18 = 83,33%. Total técnico provisório: 6/12 = 50,00% dos marcos concluídos.**

## Macroentrega 09 — Respostas privadas sem cache

Novo `ApiSecurityHeaders` no grupo API impõe `Cache-Control: no-store, private`, `Pragma: no-cache`, `nosniff`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY` e CSP limitada às respostas JSON. O handler central replica a política em respostas de erro (401/403/422). OpenAPI 0.7 atualizado. Cinco testes PHPUnit escritos para esses comportamentos, **ainda não executados**; `php -l` passou nos dois novos arquivos PHP.

O PWA parceiro descarta sessão local offline e restringe Workbox ao shell público. Sua política passou em **8/8 testes Node nativos executados**. Nenhum endpoint financeiro foi acrescentado.

Detalhes: `docs/MACRO_09_SEGURANCA_PWA.md`. **Macro 09: 11/15 = 73,33%. Roadmap total técnico provisório: 6/12 = 50,00%** de macromarcos integralmente concluídos; homologação e escopo financeiro seguem pendentes.

## Macroentrega 10 — Continuidade operacional (PR #10)

- API com `X-Request-ID` gerado pelo servidor para correlacionar incidentes, sem confiar no cabeçalho fornecido pelo cliente.
- `LOG_API_REQUEST_SUMMARY=false` por padrão; ao habilitar, gera log mínimo (ID, rota nomeada, método, status e duração), sem dados pessoais, JWT ou payload.
- `ops/preflight-production.sh` bloqueia configurações inseguras de TLS, cache da blacklist JWT, CORS, debug e banco de QA.
- `ops/backup-qa.sh` e `ops/restore-qa.sh` permitem backup e ensaio de recuperação **somente em QA**, mantendo dumps fora do Git.
- **26/26 testes shell executados** (13 de backup + 13 de preflight), com fixtures e Docker simulado; **11/11 testes Node** do verificador de release do APP, total **37/37**. `php -l` em três novos arquivos PHP sem erro.
- **Não executado:** MySQL real, PHPUnit, Composer, Vitest, build PWA, deploy ou recuperação real.

Runbooks: `ops/README.md` e `docs/MACRO_10_OPERACAO.md`.

**Macro 10: 17/22 checkpoints = 77,27%. Total provisório: 6/12 macromarcos = 50,00%.** São índices de engenharia, não conclusão das operações financeiras sem especificação aprovada.

### Correção crítica de credenciais fixas no seeder

Uma alteração incorporada à main durante a macro criou uma conta administrativa com senha estática no `AdminUserSeeder`. Este PR remove essa criação: o seeder apenas prepara papel/permissão e a conta deve ser provisionada via `repasse:user:create` e `repasse:access:bootstrap`. **Caso o seeder antigo tenha sido executado, redefina imediatamente a senha e revogue tokens existentes.** A credencial anteriormente versionada continua acessível no histórico Git.

## Testes locais no MacBook — MySQL sem Docker

O caminho suportado para desenvolvimento com PHP + MySQL nativos é **`bash qa/run-local.sh`**, com banco próprio de QA terminando em `_qa`. Ele executa PHPUnit **somente em MySQL**, sem SQLite e sem Docker. Não usar `qa/run.sh` neste ambiente (é o runner do Compose).

Primeiro `composer install`, configurar uma conta e um banco MySQL exclusivos para testes, e exportar `QA_DB_NAME`, `QA_DB_USERNAME`, `QA_DB_PASSWORD`, `QA_DB_HOST`, `QA_DB_PORT`. O runner exige usuário que não seja `root/admin`, host loopback e recusa schema com tabelas sem autorização explícita `QA_ALLOW_TEST_SCHEMA_REBUILD=YES`. Os testes Laravel com `RefreshDatabase` podem recriar tabelas **dentro do schema QA**.

Os autotestes Bash também foram isolados das variáveis do terminal, e os testes de backup passaram a mostrar a falha real caso haja incompatibilidade com BSD/macOS.

Guia completo: [qa/README_LOCAL_MYSQL.md](qa/README_LOCAL_MYSQL.md). Não aplicar esses comandos sobre o banco de desenvolvimento principal ou produção.

## QA local no macOS: SQLite de teste por padrão

Com PHP e Composer no MacBook, os testes automatizados usam SQLite **em memória**, enquanto a aplicação pode continuar usando MySQL normalmente.

```bash
composer install
bash qa/run-local.sh  # PHPUnit via SQLite :memory: e Pint --test
```

O `phpunit.xml` força `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` e `DB_URL` vazio mesmo que o terminal tenha variáveis MySQL exportadas. O runner não usa Docker nem modifica o banco MySQL da aplicação. Opcionalmente, `QA_MODE=mysql bash qa/run-local.sh` valida um banco **dedicado e descartável** `*_qa` com controles extras antes de rodar testes. Não apontar PHPUnit para um banco MySQL com dados a preservar.

Foram corrigidas também a herança indesejada de variáveis no `qa/selftest.sh` e a diagnóstica/compatibilidade dos scripts de autoteste operacional no macOS. Ver `qa/README_LOCAL_MYSQL.md` para procedimentos.

## Entrega QA macOS — correção do Laravel Pint

Os resultados reais de homologação no MacBook mostraram **59 testes PHPUnit / 329 asserções aprovados** e **10/10 + 13/13 + 13/13 autotestes Shell aprovados**, mas o `pint --test` apontou quatro arquivos de segurança do MySQL local. Eles foram reformatados sem alterar as regras de acesso e verificados com `php -l` (4/4) e com os 13 testes nativos PHP da política (13/13).

O comando `bash qa/run-local.sh` agora executa **49 gates preventivos** (10+13+13+13), depois `vendor/bin/pint --test` e somente então PHPUnit SQLite em memória. O MySQL da aplicação permanece inalterado. `QA_MODE=mysql` continua uma opção **explícita** que exige banco descartável `*_qa`.

O Pint real e o runner completo **ainda precisam ser reexecutados no Mac após este PR**. [Procedimento de aceite](docs/QA_MACOS_PINT_ESTABILIZACAO.md).

## Entrega técnica QA React — macOS sem Docker

O log real do Mac confirmou: **59 testes PHPUnit, 329 asserções, Pint em 67 arquivos e 49 checks preventivos aprovados**. Removido o aviso de `--configuration` duplicado: SQLite padrão usa `php artisan test` sem argumentos de configuração; MySQL QA opcional usa `vendor/bin/phpunit --configuration phpunit.local-mysql.xml`.

Para a API, `QA_MODE=sqlite bash qa/run-local.sh` continua usando SQLite apenas no PHPUnit, sem alterar o MySQL da aplicação. Para validar o APP, use `npm ci && npm run qa:local` no repositório React. A integração real, opcional, exige Laravel local e `QA_INTEGRATION=1 npm run qa:local`.

**21/21 testes novos Node executados** com mocks/fixtures; ainda falta executar Vitest, ESLint, build e smoke HTTP reais no Mac. Guia: `docs/MACRO_11_QA_REACT_LOCAL.md`. Entrega técnica 11 **16/20 = 80%**; roadmap canônico **6/12 = 50%**.

## Macroentrega técnica 12 — headers de erro e IDs antes do roteamento

A API agora gera `X-Request-ID` também em erros 404/405 de URL ou método inexistente. O middleware é registrado globalmente mas **atua somente em `api/*`**, e protege também preflight OPTIONS com `Cache-Control: no-store, private`. Não há modificações financeiras nem migrations. Foram escritos 7 testes Laravel para 404/405/401/422/OPTIONS, ainda não executados.

No APP pareado, respostas atrasadas de login/refresh/senha não podem restaurar sessões após logout/offline; 7 testes Node da proteção de geração executados e aprovados. Guia: `docs/MACRO_12_SESSAO_CORRELACAO.md`. Macro técnica 12 **17/21 (80,95%)**; roadmap canônico **6/12 (50,00%)** de marcos concluídos.
