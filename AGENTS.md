# AGENTS — Fluxo Repasse API (Macroentrega 03)

## Fundamentos
Laravel 12, PHP >=8.3, MySQL 8+, API REST `/api/v1`. Frontend React/Vite/PWA no repositório `fluxo-repasse-app`.
O histórico funcional Gemini não foi recuperado: não inventar fluxos financeiros, comissões, repasses, multitenancy ou perfis.

## Requisitos inegociáveis
1. pt-BR/UTF-8, validação FormRequest, Swagger/OpenAPI atualizado por endpoint e teste feature por mudança.
2. Usuário autenticado não possui direitos financeiros por padrão; rotas de dinheiro inexistem até RBAC e escopo aprovados.
3. Token Bearer JWT exclusivamente no header. Config `JWT_SECRET` somente em ambiente. Verificar `auth_version` e `is_active` a cada requisição privada.
4. Em troca de senha e status de conta, incrementar `auth_version` em transação, revogando todos os JWTs existentes.
5. Auditoria mínima de identidade, sem senhas, JWT, dados financeiros ou IP bruto; retenção LGPD pendente de aprovação.
6. Jamais adicionar senha fixa/usuário exemplo em seeder de produção; contas reais por `repasse:user:create`.
7. Qualquer escrita futura de dinheiro exige transações, locks, idempotência, testes de concorrência, rastreabilidade e valores monetários sem `float`.
8. Não executar `migrate:fresh` em ambiente persistente; manter migrations aditivas, backups e estratégia de rollback.
9. Sem segredos em arquivos versionados; `composer.lock` obrigatório para homologar, nunca forjar versão ou hash.
10. Executar `composer test`, `composer lint`, testes MySQL 8 reais e revisar `docs/QA.md` antes do merge.
11. PRs não draft; entregar documentação e relatório de resultados reais. Não alegar execução de testes não executados.

## Macroentrega 04 — regras adicionais de acesso

- `users.manage` é uma permissão técnica inicial, sem acesso financeiro; jamais equiparar a superpoderes sobre repasses.
- Todo endpoint administrativo requer `bearer.only`, `auth:api`, `session.current` e `permission:users.manage`.
- Toda alteração deve estar documentada em `docs/openapi.yaml` e testada com usuário comum, admin e não autenticado.
- Criar contas sem papéis por padrão; concessões e revogações de privilégio exclusivamente via Artisan confirmado, preservando o último admin ativo.
- Proteger a última conta administradora ativa e impedir alteração de status própria por HTTP.
- Auditoria de modificações administrativas deve registrar `actor_user_id` e `user_id`, sem dados sigilosos.
- Não assumir multitenancy ou escopo financeiro; regras dependem da fonte funcional original.
- Exigir lockfiles, testes MySQL, PHPUnit, Pint e validação ponta a ponta antes de homologação.

## Macro 05 — integridade e auditoria administrativa

- Consultas `/admin/users/{user}/events` exigem `users.manage` e retornam apenas os campos públicos definidos em `docs/openapi.yaml`.
- Não expor `ip_hash`, endereços IP, tokens, senhas ou payloads em respostas de histórico.
- Qualquer alteração de e-mail incrementa `auth_version` em transação e invalida JWTs antigos.
- Rejeitar explicitamente tentativas de alterar campos privilegiados via endpoints de usuários comuns; verificar 422 em testes.
- Mudanças de auditoria exigem testes de 401, 403, 404, filtros, paginação e privacidade.
- Smoke Node é leitura da API com login/logout de QA (gera eventos de auditoria); não utiliza credenciais em código e não altera cadastros.
- `composer.lock` e testes reais são gates formais; não declarar homologação com testes apenas escritos.

## Macro 06 — Prontidão, evidência e progresso

- Health não é readiness: `/api/v1/ready` deve testar conexão DB e tabelas essenciais e responder 503 de forma genérica quando indisponível.
- Não misturar 8 testes nativos Node executados com 4 testes Laravel apenas escritos. Evidência precisa identificar ambiente e comando.
- Não incluir segredos, SQL, nomes de hosts ou stack trace em respostas de readiness.
- Atualizar `docs/PROGRESSO_GERAL.md` com 12 marcos fixos; porcentagens se referem ao índice de roadmap, não à conclusão funcional financeira.
- Antes de produção: Composer/npm lockfiles genuínos, QA MySQL/PHPUnit/Vitest/build e testes mobile.

## Macro 07 — política de QA e medição

- Não fabricar `composer.lock`/`package-lock.json`; instalá-los e versioná-los apenas após resolução real de dependências.
- Usar MySQL QA privado e banco obrigatoriamente `*_qa`; exigir segredos próprios, nunca de produção.
- `qa/run.sh` executa migrations normais no banco isolado, sem `migrate:fresh` e sem destruição de volumes.
- Diferenciar seis verificações shell executadas de testes Laravel/MySQL/Composer não executados.
- Percentual global do roadmap é `marcos concluídos / 12`; marco 07 segue incompleto até locks e QA executado.

## Macro 08 — não declarar QA verde sem infraestrutura

- `phpunit.xml` permanece SQLite local; QA MySQL exige `--configuration phpunit.mysql.xml`, que bloqueia ambiente inseguro antes do autoload.
- Testes locais **executados**: 10 PHP + 10 shell + 15 Node = 35 aprovados. Não afirmar PHPUnit/MySQL/Vitest/build aprovados.
- `composer.lock` já está versionado; validar com Composer verdadeiro, sem inventar hashes. O script opt-in em `qa/resolve-composer-lock.sh` é apenas para clones sem lock.
- QA full exige duas contas distintas, uma com users.manage e outra sem; verificar 403 sem mutações de cadastro.
- Percentual macro 08 = 15/18 (83,33%); total de marcos concluídos = 6/12 (50,00%). Não apresentar isto como progresso de funcionalidades financeiras.
- Documento de contexto: `docs/MACRO_08_QA_MYSQL_RBAC.md` e `docs/PROGRESSO_GERAL.md`.

## Macro 09 — privacidade offline e cabeçalhos HTTP

- Toda resposta `/api/*`, inclusive 401/403, deve receber cabeçalhos no-store/private e nosniff; validar com PHPUnit.
- O PWA só pode armazenar o shell público em precache; nenhuma resposta JSON com JWT ou dados pessoais entra em runtimeCaching.
- Ao ficar offline, a aplicação não deve exibir dados privados nem restaurar a sessão automaticamente; revogação remota não pode ser prometida sem conexão.
- Testes desta macro: 8 Node PWA **executados**; 5 PHPUnit **escritos, não executados**; php -l em dois arquivos aprovado.
- Métricas: macro 09 11/15 (73,33%); roadmap total 6/12 (50,00%) de marcos fechados; não equivale a conclusão funcional financeira.

## Macro 10 — deploy sem atalhos

- Use `X-Request-ID` gerado pelo servidor para suporte; não registrar payload, JWT, IP, query ou e-mail na telemetria opcional.
- Pré-flight `ops/preflight-production.sh` é somente-leitura; não confundir com deploy realizado.
- `ops/backup-qa.sh` e `ops/restore-qa.sh` são exclusivos do Compose QA e nunca substituem backup criptografado de produção.
- Antes de liberar produção, exigir Composer/PHPUnit/MySQL, npm/Vitest/build, smoke com credenciais QA, restore real em schema separado e governança de retenção.
- Evidências reais: 13 autotestes backup + 13 preflight + 11 release Node = 37 aprovados; testes PHP/React integrados apenas escritos.
- Percentual macro 10: 17/22 (77,27%). Progresso global provisório: 6/12 (50,00%) de marcos fechados, não de funcionalidades financeiras.

## Correção QA nativo no macOS

- Para ambiente do usuário PHP + MySQL local, **sem Docker e sem SQLite**, usar `qa/run-local.sh` e `phpunit.local-mysql.xml`. `qa/run.sh` permanece exclusivo do Compose.
- Bloquear bancos sem sufixo `_qa`, hosts não loopback, senha vazia, usuário root/admin e DB_URL. Validar conexão PDO e presença de tabelas antes do PHPUnit.
- Testes com RefreshDatabase podem recriar dados no schema de testes. Permitir schema populado apenas com `QA_ALLOW_TEST_SCHEMA_REBUILD=YES` após confirmação humana de descartabilidade.
- Nunca armazenar senhas de teste no Git nem sugerir `migrate:fresh` sobre bases existentes.
- `qa/selftest.sh` não deve herdar variáveis do shell. `ops/selftest.sh` deve mostrar a falha simulada quando ocorrer.
- Evidência: 13/13 testes PHP da política MySQL local executados em cópia do código; integração real no Mac está pendente.

## Padrão de QA do MacBook autorizado: SQLite em memória

- Em ambiente nativo macOS, o comando principal é `bash qa/run-local.sh` com `QA_MODE=sqlite` implícito, PHPUnit em SQLite :memory: e Pint. A aplicação continua configurada em MySQL.
- O `phpunit.xml` deve forçar `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` e `DB_URL` vazio; não depender de variáveis herdadas.
- O MySQL local é **opcional** para teste de compatibilidade e só pode ser selecionado com `QA_MODE=mysql` e banco QA dedicado `*_qa`; nunca com banco produtivo.
- Tests de integração SQLite não provam compatibilidade completa de migrations e SQL com MySQL. A aprovação de produção requer QA MySQL separado.
- Não declarar sucesso de autotestes Bash, Vitest ou MySQL sem execução real no Mac.

## Aceite QA local/Pint após logs do operador

- Fonte de verdade de execução: Composer OK, PHPUnit SQLite 59/59 (329 assertions), autotestes Shell QA 10/10 + operações 13/13 + preflight 13/13 aprovados no Mac.
- Pint original falhou em quatro arquivos `qa/mysql-local-*.php` e bootstrap; reformatados no PR corretivo. Não alegar `vendor/bin/pint --test` aprovado até o Mac executar.
- `qa/run-local.sh` executa 49 gates independentes + Pint **antes** de PHPUnit; reexecutar no Mac para confirmar o pipeline.
- SQLite em memória permanece padrão; MySQL opcional somente `QA_MODE=mysql` com schema separado `*_qa`. Nunca alterar banco da aplicação nem adicionar migrate:fresh.
- Referência: `docs/QA_MACOS_PINT_ESTABILIZACAO.md`. Índice técnico global: 6/12 = 50%.

## Entrega técnica 11 — QA React Mac sem Docker

- API SQLite PHPUnit no Mac: 59 testes/329 assertions, Pint 67 arquivos, 49 autotestes aprovados conforme log do operador.
- Corrigir aviso duplicado via `php artisan test` sem flag; no modo MySQL QA opcional, invocar PHPUnit direto com arquivo próprio.
- APP: `npm run qa:local` agrega preflight, testes nativos, ESLint, Vitest, build e inspeção estática de PWA; `QA_INTEGRATION=1` é opt-in para API local em loopback.
- Não declarar build/Vitest/QA real aprovados sem execução no Mac; 21/21 testes novos aprovados são com mocks/fixtures.
- O banco MySQL da aplicação é preservado; não usar migrate:fresh ou operações de repasse não especificadas.
- Progresso QA técnico 16/20 (80%); roadmap canônico 6/12 (50%) sem escopo financeiro aprovado.
