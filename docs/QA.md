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
No app: `npm run qa:smoke:test` (Node, sem npm install) e `QA_REQUIRE_AUTH=1 npm run qa:smoke` (requer ambiente e conta QA reais). Testar painel de histórico, filtros, paginação, conta desativada, sessão após edição de e-mail e permissões no backend. Os acessos de smoke criam entradas de login/logout na auditoria.

**QA não executado nesta plataforma:** Composer, PHPUnit, MySQL e dependências npm ainda indisponíveis. Ver `docs/MACRO_05_AUDITORIA_QA.md` para critérios e evidências.
