#!/usr/bin/env bash
# Testa bloqueios do gate sem Docker, rede, credenciais reais ou banco de dados.
set -Eeuo pipefail
origin="$(cd "$(dirname "$0")" && pwd -P)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/api/qa" "$work/app"
cp "$origin/run.sh" "$work/api/qa/run.sh"
failures=0
passes=0

check_block() {
  local expected="$1"; shift
  local output code
  set +e
  output="$("$@" 2>&1)"
  code=$?
  set -e
  if [[ "$code" == 2 && "$output" == *"$expected"* ]]; then
    ((passes+=1))
    printf 'OK: %s\n' "$expected"
  else
    ((failures+=1))
    printf 'FALHA: esperado bloqueio "%s"; status %d\n' "$expected" "$code" >&2
  fi
}

check_block 'composer.lock ausente' bash "$work/api/qa/run.sh"
touch "$work/api/composer.lock"
check_block 'QA_APP_PATH obrigatório' bash "$work/api/qa/run.sh"
check_block 'package-lock.json ausente' env QA_APP_PATH="$work/app" bash "$work/api/qa/run.sh"
touch "$work/app/package-lock.json"
check_block 'QA_DB_PASSWORD obrigatório' env QA_APP_PATH="$work/app" bash "$work/api/qa/run.sh"
check_block 'QA_APP_KEY deve ser gerado' env QA_APP_PATH="$work/app" QA_DB_PASSWORD=fixture QA_DB_ROOT_PASSWORD=fixture QA_APP_KEY=invalid QA_JWT_SECRET=fixture bash "$work/api/qa/run.sh"
check_block 'banco de testes deve terminar em _qa' env QA_APP_PATH="$work/app" QA_DB_PASSWORD=fixture QA_DB_ROOT_PASSWORD=fixture QA_APP_KEY=base64:fixture QA_JWT_SECRET=fixture QA_DB_NAME=production bash "$work/api/qa/run.sh"

printf 'Selftest: %d aprovações, %d falhas. Nenhum Docker ou banco acionado.\n' "$passes" "$failures"
[[ "$passes" -eq 6 && "$failures" -eq 0 ]]
