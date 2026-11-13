#!/usr/bin/env bash
# Check-list mínimo somente-leitura antes de qualquer deploy. Não imprime segredos.
set -Eeuo pipefail
cd "$(dirname "$0")/.."
errors=()
require() { [[ -n "${!1:-}" ]] || errors+=("$1 ausente."); }
for name in APP_ENV APP_DEBUG APP_URL APP_KEY JWT_SECRET JWT_BLACKLIST_ENABLED DB_CONNECTION DB_DATABASE CACHE_STORE CORS_ALLOWED_ORIGINS; do
  require "$name"
done
[[ -s composer.lock ]] || errors+=('composer.lock ausente.')
[[ "${APP_ENV:-}" == production ]] || errors+=('APP_ENV deve ser production.')
[[ "${APP_DEBUG:-}" == false ]] || errors+=('APP_DEBUG deve ser false.')
[[ "${APP_URL:-}" == https://* && "${APP_URL:-}" != *'@'* &&
   "${APP_URL:-}" != *'?'* && "${APP_URL:-}" != *'#'* &&
   "${APP_URL:-}" != *'localhost'* && "${APP_URL:-}" != *'127.0.0.1'* ]] ||
  errors+=('APP_URL deve usar HTTPS público sem credenciais ou query.')
[[ "${APP_KEY:-}" == base64:* && ${#APP_KEY} -ge 50 ]] || errors+=('APP_KEY deve ser chave Laravel base64 forte.')
[[ ${#JWT_SECRET} -ge 32 ]] || errors+=('JWT_SECRET deve ter ao menos 32 caracteres.')
[[ "${JWT_BLACKLIST_ENABLED:-}" == true ]] || errors+=('JWT_BLACKLIST_ENABLED deve ser true.')
[[ "${DB_CONNECTION:-}" == mysql ]] || errors+=('DB_CONNECTION deve ser mysql.')
[[ "${DB_DATABASE:-}" != *_qa && -n "${DB_DATABASE:-}" ]] || errors+=('DB_DATABASE de produção não pode ser banco QA.')
case "${CACHE_STORE:-}" in
  redis|database) ;;
  *) errors+=('CACHE_STORE deve ser compartilhável entre instâncias: redis ou database.') ;;
esac
IFS=',' read -r -a origins <<< "${CORS_ALLOWED_ORIGINS:-}"
[[ "${#origins[@]}" -gt 0 ]] || errors+=('CORS_ALLOWED_ORIGINS está vazio.')
for origin in "${origins[@]}"; do
  [[ "$origin" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?/?$ &&
     "$origin" != *'localhost'* && "$origin" != *'*'* ]] ||
    errors+=('CORS deve conter apenas origens HTTPS explícitas, sem wildcard.')
done

if [[ ${#errors[@]} -gt 0 ]]; then
  printf 'DEPLOY BLOQUEADO: %d requisito(s) inválido(s).\n' "${#errors[@]}" >&2
  printf ' - %s\n' "${errors[@]}" >&2
  exit 2
fi
printf 'Pré-flight de configuração aprovado. Não substitui Composer, migrations, backup/restore, smoke nem revisão humana.\n'
