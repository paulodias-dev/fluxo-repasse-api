# Instalação — Fluxo Repasse API (Macroentrega 03)

## Requisitos
PHP >=8.3, Composer 2, extensões `pdo_mysql`, `mbstring`, `openssl`, `xml`, `sodium` e demais dependências exigidas pelo Laravel; MySQL 8+; servidor HTTPS para produção.

## Primeira instalação
```bash
git clone https://github.com/paulodias-dev/fluxo-repasse-api.git
cd fluxo-repasse-api
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```
Criar banco e usuário MySQL com UTF8MB4 e privilégios mínimos. Configurar `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` no `.env`.

```bash
php artisan migrate
php artisan repasse:user:create
php artisan serve --host=127.0.0.1 --port=8000
```

## Atualização a partir da Macro 02
1. Fazer backup restaurável do banco; não executar `migrate:fresh`.
2. Atualizar código e instalar dependências com lockfile reproduzível.
3. Validar `JWT_SECRET` e cache da blacklist compartilhado em instalações com múltiplas instâncias.
4. Executar `php artisan migrate`; são adicionados `users.is_active`, `users.auth_version` e `auth_security_events`.
5. Tokens anteriores à macro 03 são rejeitados pelo middleware por não terem a claim `av`; exigir novo login.
6. Validar a troca de senha, o bloqueio da conta e a consulta a perfil com JWT novo.

## Gestão de contas via terminal
```bash
php artisan repasse:user:create
php artisan repasse:user:status usuario@dominio.com inativo
php artisan repasse:user:status usuario@dominio.com ativo
```
Status pede confirmação; em scripts de manutenção supervisionados há `--yes`. Cada alteração incrementa `auth_version` e revoga todos os tokens anteriores. Não existe cadastro nem gestão administrativa pública de usuários.

## QA
```bash
bash scripts/qa-preflight.sh
composer validate --strict
composer test
composer lint
php artisan migrate:status
php artisan route:list --path=api
```
O teste rápido PHPUnit usa SQLite em memória; execução MySQL real é obrigatória antes de homologação. `composer.lock` deve ser gerado e versionado em ambiente com Packagist/Composer; nunca inventar hash de dependência ou publicar sem lock.

## Configurações obrigatórias
`APP_DEBUG=false` fora do desenvolvimento, `APP_KEY` e `JWT_SECRET` próprios do ambiente, `JWT_TTL=15`, `JWT_REFRESH_TTL=120`, `JWT_BLACKLIST_ENABLED=true`, CORS com origem explícita e TLS. Não inserir segredos em `VITE_*` do frontend.
