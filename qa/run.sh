#!/usr/bin/env bash
# Orquestração QA local; nunca executar contra base de produção.
set -Eeuo pipefail
cd "$(dirname "$0")/.."

fail() { printf 'BLOQUEADO: %s\n' "$1" >&2; exit 2; }

[[ -f composer.lock ]] || fail 'composer.lock ausente. Gere e versione com Composer antes do QA.'
[[ -n "${QA_APP_PATH:-}" ]] || fail 'QA_APP_PATH obrigatório: diretório local do fluxo-repasse-app.'
[[ -d "$QA_APP_PATH" ]] || fail 'QA_APP_PATH não é um diretório.'
export QA_APP_PATH="$(cd "$QA_APP_PATH" && pwd -P)"
[[ -f "$QA_APP_PATH/package-lock.json" ]] || fail 'package-lock.json ausente no APP; gere e versione com npm.'

for var in QA_DB_PASSWORD QA_DB_ROOT_PASSWORD QA_APP_KEY QA_JWT_SECRET; do
  [[ -n "${!var:-}" ]] || fail "$var obrigatório no ambiente de QA (não versionar segredos)."
done
[[ "${QA_APP_KEY}" == base64:* ]] || fail 'QA_APP_KEY deve ser gerado como base64 e nunca ser reutilizado de produção.'
[[ "${QA_DB_NAME:-repasse_qa}" == *_qa ]] || fail 'O banco de testes deve terminar em _qa.'
[[ "${QA_LEVEL:-public}" == public || "${QA_LEVEL:-public}" == full ]] || fail 'QA_LEVEL: use public ou full.'

if [[ "${QA_LEVEL:-public}" == full ]]; then
  [[ "${QA_REQUIRE_AUTH:-0}" == 1 && "${QA_REQUIRE_ADMIN:-0}" == 1 ]] || fail 'QA_LEVEL=full exige QA_REQUIRE_AUTH=1 e QA_REQUIRE_ADMIN=1.'
  [[ -n "${QA_USER_EMAIL:-}" && -n "${QA_USER_PASSWORD:-}" ]] || fail 'QA_LEVEL=full exige credenciais de administrador QA.'
  [[ -n "${QA_BASIC_USER_EMAIL:-}" && -n "${QA_BASIC_USER_PASSWORD:-}" ]] || fail 'QA_LEVEL=full exige credenciais separadas de usuário comum QA.'
  [[ "${QA_USER_EMAIL}" != "${QA_BASIC_USER_EMAIL}" ]] || fail 'QA_LEVEL=full exige contas distintas para administrador e usuário comum.'
fi

command -v docker >/dev/null || fail 'Docker Engine/Compose indisponível.'
docker compose version >/dev/null || fail 'Docker Compose v2 indisponível.'

# Regressões somente-leitura executadas antes de subir serviços.
bash ops/selftest.sh
bash ops/preflight-selftest.sh

compose=(docker compose -f compose.qa.yaml)
"${compose[@]}" config --quiet
"${compose[@]}" up --wait -d mysql
"${compose[@]}" run --rm --no-deps api php qa/mysql-safety.test.php

# Migrations e testes somente no banco *_qa sem expor porta MySQL nem destruir volumes.
"${compose[@]}" run --rm --no-deps api sh -ec '
  composer install --no-interaction --prefer-dist --no-progress
  php artisan migrate --force
  php artisan test --configuration phpunit.mysql.xml
  vendor/bin/pint --test
'
"${compose[@]}" up -d api
# Aguardar prontidão real antes dos testes HTTP, sem tentativas ilimitadas.
"${compose[@]}" run --rm --no-deps app node scripts/wait-api-ready.mjs

# npm ci e testes com lockfile, sem bypass de integridade.
"${compose[@]}" run --rm --no-deps app sh -ec '
  npm ci --no-audit --no-fund
  npm run qa:lock
  npm run lint
  npm test
  npm run build
  npm run qa:native
  QA_REQUIRE_AUTH=0 QA_REQUIRE_ADMIN=0 npm run qa:smoke
'

if [[ "${QA_LEVEL:-public}" == full ]]; then
  "${compose[@]}" run --rm --no-deps app npm run qa:smoke
  "${compose[@]}" run --rm --no-deps app npm run qa:rbac
  printf 'QA FULL: testes executados. Ainda revisar PWA em navegadores reais e evidências de produção.\n'
else
  printf 'QA PARCIAL: checks públicos concluídos, sem login E2E. Para QA completo, use QA_LEVEL=full com conta exclusiva de homologação.\n'
fi
printf 'Os serviços e o volume QA foram preservados; este script não executa docker compose down -v.\n'
