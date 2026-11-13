#!/usr/bin/env bash
# Laravel on macOS/Linux, without Docker. Never reuse production MySQL.
set -Eeuo pipefail
cd "$(dirname "$0")/.."

fail() { printf 'QA LOCAL BLOQUEADO: %s\n' "$1" >&2; exit 2; }
mode="${QA_MODE:-mysql}"
[[ "$mode" == mysql ]] || fail 'Este runner local utiliza apenas MySQL; use QA_MODE=mysql.'
command -v php >/dev/null || fail 'PHP CLI indisponível.'
command -v composer >/dev/null || fail 'Composer indisponível.'
php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' ||
  fail 'PHP >=8.3 é obrigatório.'
[[ -f composer.lock ]] || fail 'composer.lock ausente.'
[[ ! -f bootstrap/cache/config.php ]] ||
  fail 'Cache de configuração Laravel presente; não executar QA com conexão potencialmente antiga.'
[[ -f vendor/autoload.php ]] ||
  fail 'Dependências ausentes: primeiro execute composer install.'
[[ -f .env || -n "${APP_KEY:-}" ]] ||
  fail 'Configure um .env local com APP_KEY ou exporte APP_KEY antes de executar.'

php qa/mysql-local-safety.test.php

for name in QA_DB_NAME QA_DB_USERNAME QA_DB_PASSWORD; do
  [[ -n "${!name:-}" ]] || fail "$name obrigatório para MySQL local de QA."
done
export APP_ENV=testing DB_CONNECTION=mysql DB_URL=''
export DB_HOST="${QA_DB_HOST:-127.0.0.1}"
export DB_PORT="${QA_DB_PORT:-3306}"
export DB_DATABASE="$QA_DB_NAME"
export DB_USERNAME="$QA_DB_USERNAME"
export DB_PASSWORD="$QA_DB_PASSWORD"
printf 'QA LOCAL: verificando MySQL exclusivo de testes, sem criar banco.\n'
php qa/mysql-local-check.php
printf 'QA LOCAL: PHPUnit em MySQL QA; RefreshDatabase pode reconstruir tabelas somente neste schema.\n'
php artisan test --configuration phpunit.local-mysql.xml

vendor/bin/pint --test
printf 'QA LOCAL CONCLUÍDO. Smoke HTTP e Vitest devem ser executados separadamente.\n'
