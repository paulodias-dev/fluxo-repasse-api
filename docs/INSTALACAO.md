# Instalação — Fluxo Repasse API (Macroentrega 03)

## Requisitos
PHP >=8.3, Composer 2, extensões `pdo_mysql`, `mbstring`, `openssl`, `xml`, `sodium` e demais dependências exigidas pelo Laravel; MySQL 8+; servidor HTTPS para produção.

## Primeira instalação
```bash
git clone https://github.com/paulodias-dev/fluxo-repasse-api.git
cd fluxo-repasse-api
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```
Criar banco e usuário MySQL com UTF8MB4 e privilégios mínimos. Configurar `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` no `.env`.

```bash
php artisan migrate
php artisan repasse:user:create
php artisan serve --host=127.0.0.1 --port=8000
```

## Atualização a partir da Macro 02
1. Fazer backup restaurável do banco; não executar `migrate:fresh`.
2. Atualizar código e instalar dependências com lockfile reproduzível.
3. Validar `JWT_SECRET` e cache da blacklist compartilhado em instalações com múltiplas instâncias.
4. Executar `php artisan migrate`; são adicionados `users.is_active`, `users.auth_version` e `auth_security_events`.
5. Tokens anteriores à macro 03 são rejeitados pelo middleware por não terem a claim `av`; exigir novo login.
6. Validar a troca de senha, o bloqueio da conta e a consulta a perfil com JWT novo.

## Gestão de contas via terminal
```bash
php artisan repasse:user:create
php artisan repasse:user:status usuario@dominio.com inativo
php artisan repasse:user:status usuario@dominio.com ativo
```
Status pede confirmação; em scripts de manutenção supervisionados há `--yes`. Cada alteração incrementa `auth_version` e revoga todos os tokens anteriores. Não existe cadastro nem gestão administrativa pública de usuários.

## QA
```bash
bash scripts/qa-preflight.sh
composer validate --strict
composer test
composer lint
php artisan migrate:status
php artisan route:list --path=api
```
O teste rápido PHPUnit usa SQLite em memória; execução MySQL real é obrigatória antes de homologação. `composer.lock` deve ser gerado e versionado em ambiente com Packagist/Composer; nunca inventar hash de dependência ou publicar sem lock.

## Configurações obrigatórias
`APP_DEBUG=false` fora do desenvolvimento, `APP_KEY` e `JWT_SECRET` próprios do ambiente, `JWT_TTL=15`, `JWT_REFRESH_TTL=20160`, `JWT_REFRESH_IAT=true`, `JWT_BLACKLIST_ENABLED=true`, CORS com origem explícita e TLS. O access token dura 15 minutos; a renovação persistente fica limitada à janela de 14 dias desde a última atividade. Não inserir segredos em `VITE_*` do frontend.

## Habilitação da Macroentrega 04

Após backup e aplicação do código da Macro 04:

```bash
composer install
php artisan migrate
php artisan repasse:user:create
php artisan repasse:access:bootstrap admin@seudominio.com
# Para retirar posteriormente a permissão (preservando o último admin ativo):
php artisan repasse:access:revoke-admin admin@seudominio.com
php artisan route:list --path=api/v1/admin
```

Substitua o endereço acima por uma conta real já criada; não é uma credencial fornecida pelo projeto. O bootstrap pede confirmação por padrão, e o usuário deve realizar novo login para receber a versão atualizada da sessão.

A migration acrescenta tabelas de RBAC e um campo `actor_user_id` à auditoria existente. Não recriar o banco de produção nem executar `migrate:fresh`. Em produção, preservar APP_KEY e JWT_SECRET.

O PWA mostra gestão de usuários somente quando `GET /auth/me` inclui `users.manage`. O backend **sempre** valida a permissão independentemente de como o cliente chama a rota.

**Pendente para homologação:** gerar e versionar `composer.lock`, executar Composer/PHPUnit/Pint, testar migrations e permissões em MySQL 8 e integrar com o PWA.

## Teste integrado da Macro 05

Após aplicar todas as migrations anteriores e iniciar API Laravel com MySQL isolado para QA:

```bash
bash scripts/qa-preflight.sh
composer test
composer lint
php artisan route:list --path=api/v1/admin
```

No repositório `fluxo-repasse-app`, com Node.js 20.19+ ou 22.12+, o smoke test não exige dependências npm:

```bash
node --test scripts/smoke-api.test.mjs
QA_API_BASE_URL=http://127.0.0.1:8000 node scripts/smoke-api.mjs
```

O primeiro comando verifica o próprio smoke com respostas simuladas de teste. O segundo comprova apenas os endpoints públicos sem credenciais. Para homologação integrada **obrigatória**, disponibilize `QA_USER_EMAIL`, `QA_USER_PASSWORD`, `QA_REQUIRE_AUTH=1` e `QA_REQUIRE_ADMIN=1` como variáveis de ambiente, sem gravar senhas no repositório. A conta de QA preferencialmente tem `users.manage` para cobrir listagem e histórico.

O login/logout do smoke gera eventos de auditoria normais, mas não cria/edita/desativa usuários nem executa operações financeiras. Nunca apontar para produção sem autorização e plano de testes.

Permanece obrigatório gerar/versionar `composer.lock` em ambiente conectado e homologar MySQL/PHPUnit antes de merge ou deploy.

## Ambiente de QA reproduzível (Macro 07)

Em vez de depender de PHP/MySQL instalados localmente, é possível usar `compose.qa.yaml` com Docker Compose v2, orientado por `qa/README.md`. É obrigatório fornecer `QA_APP_PATH`, segredos gerados de QA e `composer.lock` genuíno na API e `package-lock.json` já versionado no APP; nenhum segredo deve ser versionado. O runner impede banco fora de `*_qa` e não remove volumes automaticamente.

Primeiro executar `bash qa/selftest.sh` para verificar os bloqueios; depois `QA_LEVEL=public bash qa/run.sh`. O modo completo exige conta admin QA exclusiva e `QA_LEVEL=full`. **Ambiente Docker ainda não executado aqui.**

## Macro 08 — Resolução segura e QA MySQL

O `composer.lock` já está versionado na main. Em máquina de QA com Docker e rede, executar `composer validate --strict` e `composer install`; `bash qa/resolve-composer-lock.sh` é opt-in apenas para um clone ainda sem lockfile.

Depois de validar os locks existentes e configurar segredos próprios do QA, executar `QA_LEVEL=public bash qa/run.sh`. O runner inicia MySQL privado, executa migrations normais e PHPUnit com `--configuration phpunit.mysql.xml`, Pint, npm ci/Vitest/build e smoke público; não remove volumes.

Para `QA_LEVEL=full`, provisionar via Artisan **duas contas distintas**: administrador com `users.manage` e conta comum sem papéis. Definir `QA_USER_EMAIL`, `QA_USER_PASSWORD`, `QA_BASIC_USER_EMAIL`, `QA_BASIC_USER_PASSWORD`, `QA_REQUIRE_AUTH=1` e `QA_REQUIRE_ADMIN=1`. A falta de um perfil bloqueia o runner antes de contatar Docker. Veja `qa/README.md`.

A infraestrutura completa continua **sem teste real** neste executor; 35 testes locais de proteção foram executados, não PHPUnit/MySQL.

## Verificação Macro 09 — API + PWA

Após configurar QA isolado conforme `qa/README.md`, com lockfiles já versionados e dependências instaladas:

```bash
php artisan test --configuration phpunit.mysql.xml --filter=ApiSecurityHeadersTest
php artisan test --configuration phpunit.mysql.xml
vendor/bin/pint --test
```

No APP: `npm run qa:pwa:policy`, `npm test`, `npm run build`; inspecionar service worker instalado, Cache Storage e modos offline/reconexão. Até essas etapas, não declarar homologação. Os novos testes Node fonte executados passaram em 8/8, mas PHPUnit/MySQL/Vitest/build não foram executados aqui.

## Opção preferencial para este ambiente — MySQL local no macOS

Com PHP/Composer/MySQL instalados diretamente no Mac, utilizar **`bash qa/run-local.sh`**. Ele não exige Docker nem SQLite. Criar um schema de teste `*_qa`, com usuário MySQL exclusivo diferente de root/admin, e exportar `QA_DB_NAME`, `QA_DB_USERNAME`, `QA_DB_PASSWORD`, `QA_DB_HOST` e `QA_DB_PORT`. Não editar o `.env` principal de desenvolvimento para apontar a outro banco; o runner define variáveis de conexão apenas durante a execução.

O antigo `qa/run.sh` é específico para Compose. O `phpunit.mysql.xml` anterior também foi criado para o host interno Docker `mysql`; **não** usá-lo em teste nativo. A configuração correta agora é `phpunit.local-mysql.xml` com guardas de loopback e banco exclusivo. Não executar `migrate:fresh`. Guia: `qa/README_LOCAL_MYSQL.md`.

## QA local no macOS — SQLite por padrão, MySQL da aplicação preservado

Instalar Composer e garantir que PHP tenha `pdo_sqlite` e `pdo_mysql`. O usuário já confirmou que ambas as extensões estão carregadas no PHP CLI.

```bash
composer install
bash qa/run-local.sh
```

O runner força teste SQLite `:memory:`, não usa Docker nem toca na base MySQL do aplicativo. O PHPUnit já recebeu `force="true"` nas variáveis essenciais. Para testar compatibilidade MySQL separadamente: `QA_MODE=mysql bash qa/run-local.sh`, usando exclusivamente `QA_DB_*` de um banco `*_qa` descartável; veja `qa/README_LOCAL_MYSQL.md`. Evitar `migrate:fresh` sobre bancos existentes.

## QA API + APP local sem Docker

No Mac, a API passou 59 testes PHPUnit (329 asserções), 49 proteções e Pint em 67 arquivos na última execução informada. Após o PR da correção do aviso, repetir `QA_MODE=sqlite bash qa/run-local.sh` (MySQL operacional preservado).

No repositório APP: `npm ci`, `npm run qa:local`. Com Laravel rodando em `http://127.0.0.1:8000`, `QA_INTEGRATION=1 npm run qa:local` valida prontidão, CORS, autenticação ausente e request IDs via GET/OPTIONS. Nenhum Docker necessário. Ver `docs/MACRO_11_QA_REACT_LOCAL.md`.

## Macro 51 — instalação SaaS multiempresa (NOVO, 18/11/2026)

A modelagem foi ALTERADA de single-empresa para SaaS com banco MySQL único e `tenant_id` em todas as tabelas operacionais. Para preservar a integridade, esta migração é **somente para banco vazio**. Em ambientes com dados reais, NÃO aplicar diretamente: preparar backfill por empresa e backup/restauração. No ambiente de desenvolvimento o responsável AUTORIZOU expressamente a reinstalação destrutiva:

```bash
# SOMENTE ambiente local/descartável: apaga todas as tabelas e dados desse banco!
# Pode definir REPASSE_DEMO_PASSWORD no ambiente para conhecer a senha.
php artisan migrate:fresh --seed
```

O `DatabaseSeeder` cadastra o catálogo de permissões global e os papéis funcionais independentes em cada empresa de demonstração:
- `platform_admin`: papel técnico global, somente via CLI autorizada, nunca concedido no seeder aos usuários DEMO.
- `tenant_admin` → **Administrador:** gerencia sua própria empresa, usuários, frota, participantes e módulos permitidos.
- `tenant_user` → **Usuário:** frota, participantes e cenários sem gerenciar usuários/papéis.

Somente local, `SaasDemoSeeder` cria **duas empresas fictícias**:
- **Revenda Alfa — DEMONSTRAÇÃO**: `admin.demo-alfa@example.invalid` e `usuario.demo-alfa@example.invalid`
- **Repasse Beta — DEMONSTRAÇÃO**: `admin.demo-beta@example.invalid` e `usuario.demo-beta@example.invalid`

Cada empresa possui 5 participantes, 3 veículos (estoque/vendido/arquivado), despesas realizadas/previstas, receita extra, tarefa, checklist, modelos 60/40 e cenários/minutas demonstrativas via serviços do domínio. Nada declara pagamento externo realizado.

**Senha:** `REPASSE_DEMO_PASSWORD` (opcional, mínimo 12 chars). Sem ela, o seeder gera uma senha aleatória e a exibe **uma vez no terminal na criação dos usuários**; não é gravada em código e não é enviada por e-mail.

Provisionamento de empresa real (terminal administrativo; senha solicitada sem eco):
```bash
php artisan repasse:tenant:create repasse-nova "Repasse Nova Ltda." admin@exemplo.com.br
php artisan repasse:user:create
```
O segundo comando pergunta o slug da empresa e cria um usuário funcional. Cadastrar outros usuários via admin React também os vincula ao tenant da sessão, sem aceitar tenant_id do cliente.

**QA de isolamento:** testar dois logins de empresas diferentes, listagens, detalhe por ID de outra empresa (404), upload/download de documentos, filtros, dashboard, segurança de roles, JWT antigo e empresa desativada. `php artisan test --filter=SaasTenantIsolationTest`, `composer test`, `composer lint`. Estes comandos **não foram executados** via conector GitHub.

**Importante:** `roles` agora possui `tenant_id` para cargos de empresa; `auth_security_events` registra tenant quando a identidade é conhecida. Os papéis Administrador/Usuário são separados por tenant, não modelos editáveis compartilhados. O comando `repasse:tenant:create` também provisiona esses cargos antes da conta inicial. O login retorna a identidade da empresa no endpoint `/api/v1/auth/me`, exibida no header do React.
