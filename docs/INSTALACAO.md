# Instalação — API Laravel + MySQL

## Pré-requisitos
PHP >= 8.2 com extensões exigidas pelo Laravel (pdo_mysql, mbstring, openssl, tokenizer, xml etc.), Composer 2, MySQL 8+.
Frontend: https://github.com/paulodias-dev/fluxo-repasse-app.

## Inicialização
```bash
git clone https://github.com/paulodias-dev/fluxo-repasse-api.git
cd fluxo-repasse-api
composer install
cp .env.example .env
php artisan key:generate
```
Crie previamente um banco MySQL `fluxo_repasse` UTF8MB4 e um usuário com privilégios mínimos nesse banco. Informe no `.env` `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
Nunca use `root` para a aplicação em produção.

```bash
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```
Verifique `GET http://127.0.0.1:8000/api/v1/health`.
`/up` é o health endpoint interno do Laravel.

## QA
```bash
composer test
composer lint
php artisan route:list --path=api
php artisan migrate:status
```
O `composer.lock` deve ser gerado/commitado em ambiente com Composer e acesso ao Packagist **antes de homologação**; não foi fabricado neste bootstrap.

## Integração / produção
Configure `CORS_ALLOWED_ORIGINS` com origens explícitas (separadas por vírgula; sem caractere curinga); a versão inicial só aceita GET/HEAD/OPTIONS.
No go-live: `APP_ENV=production`, `APP_DEBUG=false`, segredo `APP_KEY` seguro, HTTPS e permissões de `storage/` e `bootstrap/cache/`.
Use gerenciador de segredos, backups testados, observabilidade, fila supervisionada e estratégias de rollback.
Não executar `migrate:fresh` fora do ambiente descartável de desenvolvimento.
Nenhum endpoint de criação de repasses existe neste bootstrap.
