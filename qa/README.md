# Macroentrega 07 — Ambiente de homologação reproduzível

O ambiente é **exclusivamente de QA**, com MySQL 8.4 privado, Laravel em PHP 8.4 e Node 22 para o APP React. Não há GitHub Actions nem comandos de remoção de volumes.

## Pré-requisitos e estrutura

Clonar `fluxo-repasse-api` e `fluxo-repasse-app` em diretórios irmãos. Instalar Docker Engine e Compose v2 em uma máquina própria para QA, com acesso aos registros Docker, Packagist e npm. Este executor de desenvolvimento **não** tem acesso a Docker, Composer, PDO MySQL ou aos registros npm, portanto os testes integrados não foram executados aqui.

Antes de iniciar, **gerar e versionar lockfiles legítimos**:

Na API, com Composer 2 e PHP 8.4/extensões instaladas (o `composer.lock` já existe na main):

```bash
composer update --no-interaction --no-scripts
composer validate --strict
git add composer.lock
```

No APP, com Node 22 (o `package-lock.json` **já existe na `main` e passou na inspeção estática**; só resolver novamente se o manifesto mudar):

```bash
npm install --package-lock-only --ignore-scripts
npm run qa:lock
git add package-lock.json
```

Os comandos são instruções de manutenção. O lockfile npm foi encontrado versionado e inspecionado (525 entradas, manifesto alinhado e todos os pacotes com HTTPS/integrity); **não foi gerado nesta entrega**. O lockfile Composer também já está na main e passou na inspeção de estrutura, mas não em `composer validate`. Não publicar um arquivo vazio ou inventado.

## Segredos locais e configuração

Definir as variáveis no ambiente de execução, preferencialmente por gerenciador de segredos e **nunca em arquivos Git ou mensagens**:

- `QA_APP_PATH`: diretório absoluto do clone APP, calculado por `cd ../fluxo-repasse-app && pwd -P`.
- `QA_DB_NAME`: por padrão `repasse_qa`, deve terminar em `_qa`.
- `QA_DB_USER`: usuário exclusivo do MySQL local de homologação.
- `QA_DB_PASSWORD`, `QA_DB_ROOT_PASSWORD`: segredos diferentes e exclusivos de QA.
- `QA_APP_KEY`: chave exclusiva no formato `base64:...`.
- `QA_JWT_SECRET`: chave JWT exclusiva, diferente de produção.

Exemplos de **geração sem valores fixos**, executados apenas na máquina de QA:

```bash
export QA_APP_PATH="$(cd ../fluxo-repasse-app && pwd -P)"
export QA_DB_PASSWORD="$(openssl rand -hex 24)"
export QA_DB_ROOT_PASSWORD="$(openssl rand -hex 24)"
export QA_APP_KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
export QA_JWT_SECRET="$(openssl rand -hex 48)"
```

Persistir esses segredos no gerenciador local de forma protegida: se um volume MySQL QA for reaproveitado, **manter a senha original**, pois trocar variável não altera automaticamente a senha no banco já criado. Não utilizar segredos de produção. Não publicar `docker inspect`, dumps ou saída com credenciais.

## Verificação sem Docker

```bash
bash -n qa/run.sh
bash -n qa/selftest.sh
bash qa/selftest.sh
```

O selftest cria apenas arquivos temporários próprios, exercita seis bloqueios e os limpa com trap. **Resultado executado nesta macro:** 6/6 cenários aprovados.

## Executar QA isolado

O runner para antes de levantar qualquer serviço quando faltam lockfiles, segredos ou diretório do APP. Não roda `migrate:fresh` nem `docker compose down -v`.

```bash
QA_LEVEL=public bash qa/run.sh
```

Após os pré-requisitos, ele executa:

1. MySQL 8.4 em rede privada sem publicar a porta 3306, com volume separado de QA.
2. `composer install`, `php artisan migrate --force` **somente no banco `*_qa`**, `php artisan test`, `vendor/bin/pint --test`.
3. Inicia a API HTTP na porta local `127.0.0.1:18080` por padrão.
4. No contêiner Node: `npm ci`, `npm run qa:lock`, `npm run lint`, `npm test`, `npm run build`, `npm run qa:native` e smoke público contra a API real.

O modo público **não equivale a homologação de autenticação**. Para o modo completo, é necessário provisionar uma conta exclusiva de administrador QA e exportar suas credenciais com proteção:

```bash
# Depois que o ambiente API está inicializado:
docker compose -f compose.qa.yaml run --rm api php artisan repasse:user:create
docker compose -f compose.qa.yaml run --rm api php artisan repasse:access:bootstrap email@dominio-de-qa.test
# Configurar QA_USER_EMAIL/QA_USER_PASSWORD por mecanismo seguro:
QA_LEVEL=full QA_REQUIRE_AUTH=1 QA_REQUIRE_ADMIN=1 bash qa/run.sh
```

A conta de QA deve possuir `users.manage`. O smoke autenticado gera eventos normais de login/logout, sem criar transações financeiras ou modificar usuários. Não usar conta ou ambiente produtivo.

## Limites e bloqueios

- A imagem de QA e o Compose estão definidos, mas **não executados** aqui (Docker ausente).
- `package-lock.json` do APP já está versionado e passou na inspeção estática; `composer.lock` da API está presente; é necessário validá-lo via Composer e executar QA integrado.
- PHPUnit, Pint, MySQL, Vitest, build e E2E mobile não estão homologados.
- A readiness `/api/v1/ready` deverá retornar HTTP 200 após as migrations; erro 503 deve bloquear o aceite.
- Para produção: exigir backup/restore, CSP/TLS, política LGPD, controle de segredos e revisões de segurança adicionais.

## Percentuais

Macro 07: **12/14 checkpoints = 85,71%**. Índice total do roadmap provisório: **6/12 = 50,00%**, pois o marco 07 ainda não pode ser concluído sem a execução integrada. Consulte `docs/PROGRESSO_GERAL.md`.

## Atualização Macro 08 — MySQL real e prova RBAC obrigatória

As instruções de **geração inicial** de `composer.lock` podem ser executadas sem Composer instalado no host, pelo comando opt-in:

```bash
bash qa/resolve-composer-lock.sh
```

Este script só funciona com Docker e acesso aos registros reais, não sobrescreve um lock existente, executa `composer update --no-install --no-scripts --no-plugins` e valida o resultado com `composer validate --strict`. Nenhuma geração foi executada nesta plataforma.

O runner agora usa `php artisan test --configuration phpunit.mysql.xml`. O bootstrap MySQL bloqueia `APP_ENV` indevido, driver SQLite, banco não exclusivo, host fora do serviço interno, DB_URL externo e senha vazia **antes do autoload PHPUnit/Laravel**. As migrations preliminares do runner são protegidas por rede privada e configurações rígidas do Compose, incluindo `DB_URL=""`.

Antes do smoke, o runner aguarda `/ready` com até 30 tentativas controladas usando o script nativo Node do APP. `QA_LEVEL=full` também exige as variáveis `QA_BASIC_USER_EMAIL` e `QA_BASIC_USER_PASSWORD` de **uma segunda conta, distinta do administrador**, sem `users.manage`. Após o smoke administrativo, executa `npm run qa:rbac` e exige 403 na listagem e no histórico. Não existem mutações de usuários ou repasses nesse teste.

Para provisionar conta comum QA, use o cadastro Artisan existente **sem** conceder `platform_admin`; não reaproveite uma conta produtiva. Credenciais não devem ser versionadas, exibidas em logs ou enviadas neste chat.

Verificação local com PHP/Node/Bash: **35/35 testes passaram**. O Compose real não pôde ser executado aqui; o `composer.lock` já está na main e requer `composer validate`/instalação real. Ver `docs/MACRO_08_QA_MYSQL_RBAC.md` para matriz de aceite.
