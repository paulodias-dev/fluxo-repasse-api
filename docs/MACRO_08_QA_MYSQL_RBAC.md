# Macroentrega 08 — PHPUnit MySQL isolado, regressões e RBAC negativo

## Objetivo

Transformar o ambiente preparado na macro 07 em uma trilha de homologação verificável, sem alegar testes de MySQL que foram executados apenas em SQLite. Corrigir regressão encontrada na auditoria e testar negação de acessos administrativos com usuário comum, sem mutações de negócio.

## Correção funcional

O teste `AdminUsersTest::test_admin_editing_user_does_not_grant_privileges` esperava o evento `account_updated` ao trocar nome e e-mail simultaneamente, mas a implementação correta gera `account_email_changed`. O teste foi ajustado para esse contrato. Não houve mudança na regra de negócio.

## Suíte Laravel em MySQL verdadeira

`phpunit.xml` continua destinado a testes locais SQLite, sem perder compatibilidade.

`phpunit.mysql.xml` é a configuração exclusiva de QA: `DB_CONNECTION=mysql` e sem `DB_DATABASE=:memory:`. A configuração utiliza `qa/phpunit-mysql-bootstrap.php`, executado **antes** do autoload Laravel e de migrations iniciadas pelo PHPUnit. A migration preliminar do runner é protegida pelo isolamento do Docker Compose, pelas variáveis fixas de conexão e pelo bloqueio de banco fora de `*_qa`.

`qa/mysql-safety.php` recusa:
- `APP_ENV` diferente de `testing`.
- Banco com driver diferente de `mysql`.
- Banco vazio ou cujo nome não seja um identificador `*_qa`.
- `DB_HOST` diferente do serviço privado `mysql` do Compose.
- `DB_PORT` diferente de `3306`.
- `DB_URL` preenchido, inclusive se apontando para outra conexão.
- `DB_PASSWORD` vazio.

O Compose fixa `DB_URL=""` para não herdar URL de conexão presente em ambiente local. A suíte não deve ser usada contra banco remoto de produção, ainda que seu nome contenha `_qa`.

### Comando executado pelo runner

```bash
php artisan migrate --force                    # somente banco privado *_qa
php artisan test --configuration phpunit.mysql.xml
vendor/bin/pint --test
```

Em uma estação sem Composer, o script opt-in `bash qa/resolve-composer-lock.sh` pode gerar um **lockfile legítimo** usando a imagem Docker de QA e `composer update --no-install --no-plugins --no-scripts`. Ele só funciona com Docker e acesso ao Packagist, não sobrescreve lock existente, valida o resultado com `composer validate --strict` e exige revisão manual antes de commit. **Não foi executado nesta plataforma; composer.lock permanece ausente.**

## Readiness com tentativas limitadas

Após iniciar `api`, o runner usa `node scripts/wait-api-ready.mjs` do APP. São até 30 tentativas, com intervalo padrão de 1 s e timeout individual de 3 s. HTTP 503/429 e falhas temporárias de rede podem ser retentados; respostas HTTP 200 sem `status: ready` e erros permanentes bloqueiam a execução. Não há tentativas infinitas.

## QA completo com dois perfis

O modo `QA_LEVEL=full` passa a exigir duas contas distintas, provisionadas no banco de homologação:

- `QA_USER_EMAIL` e `QA_USER_PASSWORD`: usuário com `users.manage` concedido pelo Artisan.
- `QA_BASIC_USER_EMAIL` e `QA_BASIC_USER_PASSWORD`: usuário comum sem papéis/permissões.

Configure `QA_REQUIRE_AUTH=1` e `QA_REQUIRE_ADMIN=1`. O runner recusa dados ausentes ou mesma conta para ambos. Ele executa o smoke administrativo e, em separado, `npm run qa:rbac`, que valida 403 em listagem e histórico para usuário comum. Os scripts não criam nem atualizam usuários ou dados financeiros; autenticação e logout geram eventos normais de auditoria.

## Testes efetivamente executados nesta macro

| Evidência | Execução | Resultado |
|---|---|---|
| Testes de proteção MySQL | `php qa/mysql-safety.test.php` | **10/10 aprovados**, PHP 8.4 |
| Proteções shell do runner | `bash qa/selftest.sh` | **10/10 aprovados**, sem Docker |
| Espera de prontidão | `node --test scripts/wait-api-ready.test.mjs` (APP) | **8/8 aprovados**, Node 22 |
| Acesso negado RBAC | `node --test scripts/probe-rbac.test.mjs` (APP) | **7/7 aprovados**, Node 22 |
| Verificação sintática | `php -l`, `node --check`, `bash -n` | Sem erros nos novos arquivos executados |

Total: **35 testes de isolamento/contrato executados e aprovados**, todos sem dependências externas. Os scripts executados foram comparados com as fontes publicadas nas branches por comprimento e checksum FNV-1a.

**Não executados:** Composer, PHPUnit/Laravel real, Pint, migrations MySQL, npm ci, Vitest, ESLint, build, smoke autenticado com API verdadeira e navegadores. Não confundir testes de isolamento/mocks com homologação da aplicação.

## Indicadores

**Macro 08:** 14/18 checkpoints = **77,78%**. Os quatro restantes são lockfile Composer, PHPUnit/MySQL/Pint real, npm/Vitest/build real e aceite integrado em navegadores.

**Roadmap global provisório:** 6/12 = **50,00% dos macromarcos concluídos**. Marcos 07 e 08 têm entregas parciais, mas não são computados como concluídos. O percentual não representa implementação do domínio financeiro, cujo escopo funcional permanece sem aprovação documentada.

## Próximos passos

Executar `qa/resolve-composer-lock.sh` em infraestrutura com acesso aos registros, revisar e versionar o lock, rodar `QA_LEVEL=public bash qa/run.sh` e `QA_LEVEL=full bash qa/run.sh` após provisionar dois usuários distintos. Corrigir as falhas reais de PHPUnit, MySQL, Vitest e PWA antes de abrir aceite de produção.
