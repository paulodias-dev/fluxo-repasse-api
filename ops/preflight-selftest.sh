#!/usr/bin/env bash
# Regressões do pré-flight com fixtures de ambiente; não toca produção.
set -Eeuo pipefail
cd "$(dirname "$0")/.."
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
mkdir -p "$work/ops"
cp ops/preflight-production.sh "$work/ops/"
printf 'somente-fixture\n' > "$work/composer.lock"
gate="$work/ops/preflight-production.sh"
passed=0; failed=0
base=(APP_ENV=production APP_DEBUG=false APP_URL=https://repasse.example.org
 APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
 JWT_SECRET=abcdefghijklmnopqrstuvwxyz0123456789 JWT_BLACKLIST_ENABLED=true
 DB_CONNECTION=mysql DB_DATABASE=repasse_prod CACHE_STORE=database
 CORS_ALLOWED_ORIGINS=https://repasse.example.org)
expect_ok() {
  local label="$1"; shift
  if env -i PATH="$PATH" "${base[@]}" "$@" bash "$gate" > "$work/output" 2>&1; then
    ((passed+=1)); echo "OK: $label"
  else
    ((failed+=1)); echo "FALHA: $label" >&2
  fi
}
expect_block() {
  local label="$1"; shift
  if env -i PATH="$PATH" "${base[@]}" "$@" bash "$gate" > "$work/output" 2>&1; then
    ((failed+=1)); echo "FALHA: aceitou $label" >&2
  else
    ((passed+=1)); echo "OK: bloqueou $label"
  fi
}
expect_ok 'configuração mínima de produção'
expect_block 'APP_ENV testing' APP_ENV=testing
expect_block 'APP_DEBUG ativo' APP_DEBUG=true
expect_block 'APP_URL HTTP' APP_URL=http://repasse.example.org
expect_block 'APP_URL com credenciais' APP_URL=https://u:p@repasse.example.org
expect_block 'APP_KEY vazia' APP_KEY=
expect_block 'JWT_SECRET curta' JWT_SECRET=weak
expect_block 'blacklist JWT desligada' JWT_BLACKLIST_ENABLED=false
expect_block 'driver SQLite' DB_CONNECTION=sqlite
expect_block 'banco de QA' DB_DATABASE=repasse_qa
expect_block 'cache local file' CACHE_STORE=file
expect_block 'CORS wildcard' CORS_ALLOWED_ORIGINS='*'
expect_block 'CORS HTTP' CORS_ALLOWED_ORIGINS=http://repasse.example.org
printf 'Selftest de preflight: %d aprovados, %d falhas; nenhuma implantação executada.\n' "$passed" "$failed"
[[ "$passed" -eq 13 && "$failed" -eq 0 ]]
