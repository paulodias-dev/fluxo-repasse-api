# QA — Macroentrega 2: identidade e confiabilidade

## Execução obrigatória antes da homologação
```bash
composer validate --strict
composer install
php artisan key:generate
php artisan jwt:secret
php artisan migrate
composer test
composer lint
php artisan route:list --path=api
php artisan migrate:status
```
Criar usuário via `php artisan repasse:user:create` e testar com senhas próprias, nunca dados reais de produção.

## Testes automatizados definidos
- Login correto emite JWT e perfil expõe somente `id`, `name`, `email`.
- Senha incorreta retorna 401 com mensagem genérica.
- `/auth/me`, `/auth/logout` e `/auth/refresh` sem JWT retornam 401.
- Logout invalida token antigo; perfil com token revogado não é acessível.
- Não existem endpoints públicos de registro nem de criação de repasses.
- Endpoint de saúde continua acessível sem autenticação.

## Cenários manuais de segurança
1. Rate-limit com 6 logins inválidos em 60s pela mesma combinação e-mail/IP → 429; reavaliar limite global por IP.
2. Token inválido ou expirado → 401, nunca 500 com stack trace.
3. Refresh com JWT válido → token novo, token antigo revogado; refresh com JWT expirado → 401.
4. Validação com e-mail ausente, formato inválido e payload inesperado → 422.
5. Criar usuário duplicado por e-mail → comando falha sem duplicação.
6. Corromper `JWT_SECRET` entre instâncias no ambiente isolado → tokens recusados.
7. Testar CORS permitido e origem não permitida; sem `Access-Control-Allow-Origin: *`.
8. Rodar migracões e testes em MySQL 8 com charset UTF8MB4.
9. Implantação: verificar HTTPS, APP_DEBUG=false, armazenamento de blacklist compartilhado, backup e restore.

## Limitação desta entrega
Não há Composer nem dependências instaladas no executor, e não há MySQL disponível. Testes e migrations foram escritos, **não executados**; a conformidade de runtime está pendente.
