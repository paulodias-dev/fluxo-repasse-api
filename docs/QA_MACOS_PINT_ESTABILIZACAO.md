# Entrega de estabilização QA — Pint no MacBook

**Contexto:** O PR #11 foi incorporado à `main`. O operador executou o QA local com PHP/Composer no macOS e forneceu os resultados reais desta entrega.

## Resultado informado pelo operador antes desta correção

| Comando | Resultado |
|---|---|
| `git pull --ff-only` | Aprovado, repositório atualizado |
| `composer install` | Aprovado, dependências satisfeitas |
| PHPUnit via `bash qa/run-local.sh` | **59 testes aprovados, 329 asserções**, SQLite em memória |
| Laravel Pint `--test` | **Falhou em 4 arquivos QA** recém-criados |
| `bash qa/selftest.sh` | **10/10 aprovados** no Mac |
| `bash ops/selftest.sh` | **13/13 aprovados** no Mac |
| `bash ops/preflight-selftest.sh` | **13/13 aprovados** no Mac |

Os quatro arquivos apontados pelo Pint:

- `qa/mysql-local-safety.test.php`
- `qa/mysql-local-safety.php`
- `qa/phpunit-local-mysql-bootstrap.php`
- `qa/mysql-local-check.php`

## Correção realizada

Os quatro arquivos foram reformatados para o padrão Laravel Pint: separação das declarações `strict_types`, blocos `if` com chaves, espaçamento dos operadores de negação, organização dos arrays, docblocks e linhas em branco. A regra de conexão segura com o MySQL foi **preservada**, inclusive os bloqueios de base fora de `*_qa`, host remoto, root/admin, DB_URL e senha vazia.

O runner `qa/run-local.sh` agora agrega os quatro conjuntos de autotestes em uma única sequência:

1. `bash qa/selftest.sh` — 10 proteções de QA.
2. `bash ops/selftest.sh` — 13 cenários de backup e restauração com Docker simulado, sem MySQL.
3. `bash ops/preflight-selftest.sh` — 13 cenários de configuração de produção com fixtures.
4. `php qa/mysql-local-safety.test.php` — 13 casos de isolamento MySQL somente com PHP.
5. `vendor/bin/pint --test` — **antes** de executar PHPUnit; fail-fast.
6. `php artisan test --configuration phpunit.xml` — SQLite `:memory:` por padrão, sem Docker e sem MySQL operacional.

O modo `QA_MODE=mysql` segue opt-in, com schema `*_qa` exclusivo, usuário MySQL dedicado, preflight PDO e prevenção contra reconstrução de tabelas que precisem ser preservadas. Não foi adicionado nenhum comando destrutivo.

## Evidência executada na preparação do PR

- `php -l` dos quatro arquivos PHP corrigidos: **4/4 sem erros** com PHP CLI 8.4.24.
- `php qa/mysql-local-safety.test.php` com as **fontes exatas publicadas no PR**: **13/13 aprovados**.
- Comparação por comprimento e checksum FNV-1a confirma que os quatro blobs enviados ao GitHub são iguais aos testados.
- As três suítes Bash de QA e operação foram aprovadas pelo operador no Mac **antes** desta mudança de orquestração: 10 + 13 + 13 = 36 verificações.
- A suíte PHPUnit padrão foi aprovada no Mac: **59 testes, 329 assertions**.

**Ainda NÃO executado:** Laravel Pint real contra os quatro arquivos corrigidos e o pipeline completo revisado no MacBook. Nenhuma alegação de aceite total até nova execução. Os resultados PHPUnit/Composer relatados são do ambiente do operador; o executor desta preparação só possui PHP CLI, sem Composer/Pint/Laravel.

## Repetição de QA após merge do PR

```bash
git pull --ff-only
composer install
vendor/bin/pint --test
bash qa/run-local.sh
```

Com `QA_MODE` não definido, `qa/run-local.sh` usa SQLite em memória. Caso haja `QA_MODE=mysql` exportado previamente, execute explicitamente `QA_MODE=sqlite bash qa/run-local.sh` para forçar a suíte padrão. O MySQL da aplicação não é modificado.

**Critérios de aceite:** Pint aprovar todos os arquivos; 49/49 gates preventivos aprovados no runner; PHPUnit 59/59 (ou nova contagem documentada sem falhas); nenhum acesso ao MySQL operacional.

## Medição

- **Estabilização QA desta entrega:** 8/10 checkpoints = **80,00%**. Faltam executar Pint real e o runner completo revisado no Mac.
- **Roadmap global técnico provisório:** 6/12 macromarcos integralmente concluídos = **50,00%**. Esta correção não fecha os testes integrados MySQL, Vitest, build ou a homologação mobile, e não representa progresso funcional das operações de repasse.
