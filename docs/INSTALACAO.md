# Fluxo Repasse API — instalação e inicialização da identidade

## Pré-requisitos
PHP 8.3+, Composer 2, extensões PHP exigidas pelo Laravel (incluindo pdo_mysql, mbstring, openssl, xml e sodium), MySQL 8+.
O pacote JWT é `php-open-source-saver/jwt-auth:^2.10` (compatível com Laravel 12).

## Instalação
```bash
git clone https://github.com/paulodias-dev/fluxo-repasse-api.git
cd fluxo-repasse-api
# Após merge do PR 2, utilizar a main.
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```
Criar previamente o banco `fluxo_repasse` em MySQL/UTF8MB4 e um usuário de acesso com permissões apenas para o banco. Preencher `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` no `.env`.

```bash
php artisan migrate
php artisan repasse:user:create
php artisan serve --host=127.0.0.1 --port=8000
```

O comando de provisionamento solicita nome, e-mail e senha de forma interativa e segura, sem credencial fixa e sem permissões financeiras. Não há rota de cadastro público.

## Endpoints de identidade
- `GET /api/v1/health` — público.
- `POST /api/v1/auth/login` — recebe `email`, `password` e retorna `access_token`, `token_type`, `expires_in`.
- `GET /api/v1/auth/me` — exige `Authorization: Bearer TOKEN`.
- `POST /api/v1/auth/refresh` — exige JWT **ainda válido**, rotaciona token e revoga o antigo.
- `POST /api/v1/auth/logout` — exige JWT, invalida token em cache compartilhado.

Contrato completo: `docs/openapi.yaml`.

## Verificação
```bash
composer validate --strict
composer test
composer lint
php artisan route:list --path=api
php artisan migrate:status
```
Testes unitários/feature usam SQLite em memória por padrão. Fazer rodada adicional no MySQL 8, antes de homologar. Ver `docs/QA.md`.

## Atenção — dependências reprodutíveis
`composer.lock` ainda está pendente porque esta entrega não dispõe de acesso ao Packagist e não executou Composer. **Antes de aprovar o deploy**, gerar o lock em ambiente íntegro com `composer update` e versioná-lo. Em seguida usar sempre `composer install`. Não afirmar testes verdes até executar.

## Segurança de ambiente
`JWT_SECRET` deve ser exclusivo do ambiente, nunca versionado. Ajustes: `JWT_TTL=15` minutos, `JWT_REFRESH_TTL=120` minutos, blacklist habilitada, cache de blacklist centralizado em produção. HTTPS, `APP_DEBUG=false`, proteção da `.env` e origens CORS específicas são obrigatórios.
