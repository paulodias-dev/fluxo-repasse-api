#!/usr/bin/env bash
# Stage 6: ONE local operational acceptance gate for API + purchased React app.
# No migrations, no seeders, no bank actions, no deletion of QA volumes.
set -Eeuo pipefail
cd "$(dirname "$0")/.."

fail() { printf 'ETAPA 6 BLOQUEADA: %s\n' "$1" >&2; exit 2; }

stage="${QA_STAGE:-static}"
case "$stage" in static|local|full) ;; *) fail 'QA_STAGE deve ser static, local ou full.' ;; esac

command -v php >/dev/null || fail 'PHP CLI indisponível.'
command -v node >/dev/null || fail 'Node.js indisponível.'
[[ -f composer.lock ]] || fail 'composer.lock versionado ausente.'
[[ -n "${QA_APP_PATH:-}" ]] || fail 'QA_APP_PATH deve apontar explicitamente para o checkout local do React.'
[[ -d "$QA_APP_PATH" ]] || fail 'QA_APP_PATH inexistente.'
app_path="$(cd "$QA_APP_PATH" && pwd -P)"
[[ "$app_path" != "$(pwd -P)" ]] || fail 'QA_APP_PATH não pode ser a própria API.'
[[ -f "$app_path/package.json" && -f "$app_path/package-lock.json" ]] ||
  fail 'O checkout do React deve conter package.json e package-lock.json.'
[[ -f "$app_path/scripts/qa-integrated.mjs" ]] || fail 'Macro 29 ainda não aplicada no React.'

printf 'ETAPA 6: verificações estáticas (nenhum banco ou serviço é alterado).\n'
php -l app/Support/OperationalAcceptance.php
php -l routes/console.php
node --test "$app_path/scripts/qa-integrated.test.mjs"

if [[ "$stage" == static ]]; then
  printf 'ETAPA 6: validação estática concluída. Não equivale a PHPUnit/build/QA HTTP.\n'
  exit 0
fi

command -v composer >/dev/null || fail 'Composer indisponível.'
command -v npm >/dev/null || fail 'npm indisponível.'
[[ -f vendor/autoload.php ]] || fail 'Instale dependências com composer install antes da etapa local.'
[[ "${QA_MODE:-sqlite}" == sqlite || "${QA_MODE:-sqlite}" == mysql ]] ||
  fail 'QA_MODE inválido. Utilize sqlite ou mysql com schema exclusivo *_qa.'

printf 'ETAPA 6: executando QA Laravel/Pint e testes em banco isolado via runner oficial.\n'
# Existing runner enforces sqlite :memory: or protected MySQL *_qa. It may
# rebuild an explicitly disposable QA schema; never point it at operational DB.
bash qa/run-local.sh

printf 'ETAPA 6: validando compilação e checks React do checkout adquirido.\n'
(
  cd "$app_path"
  npm ci --no-audit --no-fund
  npm run lint
  npm run build
)

if [[ "$stage" == local ]]; then
  printf 'ETAPA 6: Laravel/Pint/React aprovados localmente. QA HTTP com duas contas ainda pendente.\n'
  exit 0
fi

[[ "${QA_LEVEL:-}" == full ]] || fail 'QA_STAGE=full exige QA_LEVEL=full.'
[[ "${QA_HTTP_VERIFIED:-}" == YES ]] ||
  fail 'Inspecione banco e configuração do Laravel local antes de definir QA_HTTP_VERIFIED=YES.'
[[ -n "${QA_VEHICLE_ID:-}" ]] || fail 'QA_VEHICLE_ID é obrigatório; use um veículo de homologação real.'
for name in QA_USER_EMAIL QA_USER_PASSWORD QA_BASIC_USER_EMAIL QA_BASIC_USER_PASSWORD; do
  [[ -n "${!name:-}" ]] || fail "$name é obrigatório para QA HTTP completo."
done

printf 'ETAPA 6: smoke HTTP sem mutações de negócio, usando duas contas exclusivas de QA.\n'
(
  cd "$app_path"
  npm run qa:acceptance
)
printf 'ETAPA 6: gates automatizados concluídos. Ainda requer inspeção visual mobile/desktop e aceite humano.\n'
