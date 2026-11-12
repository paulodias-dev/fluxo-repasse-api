#!/usr/bin/env bash
set -uo pipefail

cd "$(dirname "$0")/.."
fail=0

report_failure() {
  echo "ERRO: $1" >&2
  fail=1
}

if ! command -v php >/dev/null 2>&1; then
  report_failure "PHP não instalado"
else
  if ! php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then
    report_failure "É obrigatório PHP 8.3 ou superior"
  fi

  for ext in pdo_mysql mbstring openssl xml sodium; do
    if ! php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$ext"; then
      report_failure "Extensão PHP ausente: $ext"
    fi
  done

  while IFS= read -r -d '' source; do
    if ! php -l "$source" >/dev/null; then
      report_failure "Falha de sintaxe PHP: $source"
    fi
  done < <(find app bootstrap config routes database tests -type f -name '*.php' -print0)

  echo "Verificação sintática PHP concluída."
fi

if ! command -v composer >/dev/null 2>&1; then
  report_failure "Composer não instalado"
else
  if ! composer validate --strict --no-interaction; then
    report_failure "composer validate falhou"
  fi
fi

if [ ! -f composer.lock ]; then
  report_failure "composer.lock ausente: resolva e versione as dependências antes do deploy"
fi

if [ ! -f .env ] && [ -z "${APP_KEY:-}" ]; then
  report_failure "Sem .env e sem APP_KEY configurado no ambiente (não versionar segredos)"
fi

if [ ! -f vendor/autoload.php ]; then
  report_failure "Dependências PHP não instaladas: execute composer install"
fi

if [ "$fail" -ne 0 ]; then
  echo "Pré-homologação bloqueada; corrija os itens acima." >&2
  exit 1
fi

echo "Pré-checagem aprovada. Ainda é obrigatório executar testes, migrations MySQL e QA integrado."
