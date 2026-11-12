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
