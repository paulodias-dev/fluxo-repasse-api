# QA — Macroentrega 03 (API)

## Matriz de teste automatizado
Além de `AuthApiTest` da macro 02, `AuthLifecycleTest` adiciona:
1. Alteração de senha só com Bearer válido.
2. Senha atual incorreta → 422, sem alterar a credencial.
3. Senha nova fraca → 422.
4. Senha alterada → JWT antigo de qualquer sessão retorna 401; login com senha antiga falha; nova funciona.
5. Conta inativa → login recusado e JWT previamente emitido retorna 401.
6. CLI de desativação → aumenta `auth_version`, audita evento e invalida token.
7. Refresh com JWT válido preserva a versão `av` e revoga token anterior.
8. Auditoria de login falho sem senha, e-mail fornecido ou IP em claro.
9. Cenários anteriores: throttle 429, exigência de Bearer no header, logout e rotação.

## Comandos
```bash
bash scripts/qa-preflight.sh
composer validate --strict
composer install
php artisan migrate
composer test
composer lint
php artisan route:list --path=api
```
Testar com `.env.testing` isolado. Não apontar PHPUnit para base de produção. Executar também em MySQL 8+; SQLite é apenas teste rápido.

## Aceite de banco e segurança
- Migrations aditivas, rollback planejado, `is_active` e `auth_version` com defaults.
- `auth_security_events` não armazena token/senha/CPF/e-mail digitado nem IP bruto.
- Simular duas sessões concorrentes antes/depois da troca de senha; verificar invalidação cruzada.
- Auditar cache de JWT blacklist em múltiplas instâncias e comportamento de refresh.
- Desativação/reativação via Artisan com confirmação, sem cadastro público.
- Rotas financeiras devem permanecer 404 até definição de regras de negócio.
- Testar bloqueio por rate-limit e duração do JWT.
- Verificar CORS, TLS, erros sem stack trace e `APP_DEBUG=false` em produção.

## Limitações desta entrega
PHP disponível localmente, mas sem Composer, pdo_mysql, base MySQL nem acesso ao registro de pacotes. Não é possível alegar PHPUnit, migrations ou integração reais executados. Validar integralmente antes do merge e go-live.

## Matriz adicional — Macroentrega 04

Os testes `tests/Feature/AdminUsersTest.php` verificam:
1. Rotas administrativas exigem autenticação e retornam 403 sem `users.manage`.
2. `/auth/me` expõe permissão técnica concedida explicitamente, sem senha.
3. Listagem filtra por nome/e-mail e não devolve `password` nem `auth_version`.
4. Cadastro aceita dados validados, não concede papel ou acesso financeiro automaticamente e registra operador.
5. E-mails duplicados e senhas fracas são recusados.
6. Edição de nome/e-mail não aceita concessão de papéis.
7. Desativação de usuário comum incrementa versão, revoga JWT e audita o autor.
8. API bloqueia desativar a própria conta e outra conta administradora.
9. Bootstrap Artisan concede papel técnico a uma conta existente e audita.
10. Console impede desativação do último administrador ativo.
11. Console impede revogar a permissão do último administrador ativo.
12. Revogar administração invalida tokens antigos e audita a alteração.

**Critérios adicionais de homologação:** testar paginação, filtros de status, edições concorrentes, rollback das migrations, 401/403/422/429, integridade do banco e atuação de dois administradores. Nenhum requisito financeiro está testado ou implementado.

Comandos: `bash scripts/qa-preflight.sh`, `composer test`, `composer lint`, `php artisan migrate:status`. Os testes foram escritos mas **não executados** nesta entrega por indisponibilidade das dependências PHP/MySQL; não considerar homologado.

## Macro 05 — Casos de auditoria, sessão e mass assignment

### API
- Conta não autenticada → 401 ao consultar histórico.
- Conta autenticada sem `users.manage` → 403.
- Administrador → histórico somente do usuário consultado, sem hash de IP ou segredos.
- Filtros `type/from/to/page` válidos e inválidos → paginação correta, 422 no caso inválido.
- Conta inexistente → 404.
- Edição de e-mail → JWT anterior retorna 401; registra operador/conta e `account_email_changed`.
- Edição somente do nome → mantém JWT válido; no-op não gera evento novo.
- Payload com `roles`, `permissions`, `password`, `auth_version` fora das rotas próprias → 422 e ausência de elevação de privilégios.

### Ponta a ponta
No app: `npm run qa:smoke:test` (Node, sem npm install) e `QA_REQUIRE_AUTH=1 QA_REQUIRE_ADMIN=1 npm run qa:smoke` (requer ambiente e conta QA reais). Testar painel de histórico, filtros, paginação, conta desativada, sessão após edição de e-mail e permissões no backend. Os acessos de smoke criam entradas de login/logout na auditoria.

**QA não executado nesta plataforma:** Composer, PHPUnit, MySQL e dependências npm ainda indisponíveis. Ver `docs/MACRO_05_AUDITORIA_QA.md` para critérios e evidências.

## Execução/verificação real registrada — Macro 06

- `php -l ReadinessController.php`: sem erros de sintaxe em PHP 8.4.24 (cópia idêntica da branch).
- `php -l ReadinessTest.php`: sem erros de sintaxe em PHP 8.4.24.
- `node --test scripts/smoke-api.test.mjs` no APP: 8/8 testes nativos passaram, Node v22.16.0, usando mocks.
- `ReadinessTest.php` contém 4 cenários Laravel **escritos e não executados**: DB pronto, tabela ausente, exceção de conexão sem vazamento e health independente.
- **Ainda não executado:** Composer install, PHPUnit, Laravel Pint, migrations MySQL, Vitest, build ou teste PWA em navegador.
- Critérios restantes para homologação: `composer.lock`, `package-lock.json`, infraestrutura MySQL de QA, suite real e smoke autenticado.

Ver `docs/MACRO_06_READINESS_QA.md` e `docs/PROGRESSO_GERAL.md` para comandos, evidências e progresso.

## Macroentrega 07 — Execução de QA sem dependências externas

Verificações executadas: `bash -n qa/run.sh`, `bash -n qa/selftest.sh`, `bash qa/selftest.sh` (**6 testes aprovados**), análise sintática de `compose.qa.yaml` com PyYAML (3 serviços, MySQL sem porta publicada). Em paralelo o APP executou **9 testes Node aprovados** do validador de `package-lock.json`.

O Compose prepara o caminho para PHP 8.4 + MySQL 8.4 + React, mas **não foi executado** neste ambiente. Para QA integrado, seguir `qa/README.md`: criar/versionar o lock Composer legítimo e validar o lock npm já existente com npm ci, fornecer chaves e credenciais de QA e executar `QA_LEVEL=public bash qa/run.sh` ou `QA_LEVEL=full` com conta administrativa de QA.

Os testes reais de PHPUnit, Pint, MySQL, Vitest, lint/build e PWA mobile seguem bloqueados. Nenhum resultado dessa fase deve ser rotulado como homologação de produção.

## Macro 08 — Evidências de execução e restrições

| Suite | Resultado |
|---|---|
| `php qa/mysql-safety.test.php` | 10/10 aprovados, PHP 8.4 |
| `bash qa/selftest.sh` | 10/10 aprovados, sem Docker |
| `node --test scripts/wait-api-ready.test.mjs` (APP) | 8/8 aprovados, Node 22 |
| `node --test scripts/probe-rbac.test.mjs` (APP) | 7/7 aprovados, Node 22 |
| `php -l`, `bash -n`, `node --check` em arquivos novos | Sem erros |

A configuração MySQL própria executa o bootstrap fail-closed e não usa SQLite. O runner aguarda prontidão da API e, no modo full, valida 403 em listagem e histórico com uma conta comum diferente da administradora.

**Não executados nesta plataforma:** `composer install`, `php artisan test --configuration phpunit.mysql.xml`, Pint, MySQL real, npm ci, Vitest/build, smoke conectado e E2E mobile. `composer.lock` presente, 113 pacotes inspecionados; Composer/Docker/registry indisponíveis para execução. Testes isolados não constituem homologação.

O lock Composer existente ainda precisa passar por `composer validate --strict` e instalação. Ver `docs/MACRO_08_QA_MYSQL_RBAC.md` para condições e `docs/PROGRESSO_GERAL.md` para percentuais.

## Macro 09 — Política de respostas privadas

**Executado:** `php -l` no `ApiSecurityHeaders.php` e `ApiSecurityHeadersTest.php` (2/2 sem erros), e `node --test src/pwa/cachePolicy.test.mjs` no APP (8/8 aprovados, Node 22.16.0). Os arquivos executados foram comparados à branch via comprimento e hash FNV-1a.

**Escrito mas NÃO executado:** `php artisan test --configuration phpunit.mysql.xml --filter=ApiSecurityHeadersTest` (5 cenários), mais 3 testes Vitest no APP de sessão offline/login pendente/acessibilidade. Composer/MySQL e npm ci/Vitest/build indisponíveis aqui.

Aceite obrigatório: conferir Cache-Control/Pragma e demais headers em 200/401/403/422/503; inspecionar Cache Storage de PWA instalado com login real e verificar que não guarda nenhuma resposta autenticada. Confirmar comportamento em smartphone e WCAG via testes adequados.

Ver `docs/MACRO_09_SEGURANCA_PWA.md` e `docs/PROGRESSO_GERAL.md`.

## Evidência real — Macroentrega 10

| Verificação | Status |
|---|---|
| `bash ops/selftest.sh` | **13/13 aprovados** com Docker simulado, sem MySQL |
| `bash ops/preflight-selftest.sh` | **13/13 aprovados** com variáveis de teste |
| `node --test scripts/verify-release.test.mjs` (APP) | **11/11 aprovados**, bundle temporário |
| `php -l` middleware, config e teste feature de X-Request-ID | **3/3 sem erro** |
| `bash -n` scripts operacionais e `node --check` do verificador | Sem erro |

Arquivos efetivamente testados foram comparados aos blobs GitHub por tamanho e FNV-1a. Ao todo **37/37 testes nativos novos executados**, com fixtures/mocks, **não** com MySQL ou browser real.

**Escritos mas não executados:** cinco testes Laravel de correlação e dois Vitest de fallback React. Tampouco foram executados Composer, migrations, PHPUnit/Pint, npm ci/build, restore real ou deploy.

Ver `ops/README.md`, `docs/MACRO_10_OPERACAO.md` e `docs/PROGRESSO_GERAL.md`.
