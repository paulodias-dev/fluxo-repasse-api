#!/usr/bin/env bash
# Ensaio explícito de restauração: cria NOVO schema *_restore_qa, nunca sobrescreve.
set -Eeuo pipefail
cd "$(dirname "$0")/.."
fail() { printf 'RESTAURAÇÃO BLOQUEADA: %s\n' "$1" >&2; exit 2; }

[[ $# -eq 1 ]] || fail 'Informe um único backup .sql.gz.'
archive="$1"
[[ "${QA_DB_NAME:-repasse_qa}" =~ ^[a-zA-Z0-9_]+_qa$ ]] || fail 'Origem deve ser QA.'
[[ "${QA_RESTORE_DATABASE:-}" =~ ^[a-zA-Z0-9_]+_restore_qa$ ]] || fail 'Destino deve ser um novo banco *_restore_qa.'
[[ "$QA_RESTORE_DATABASE" != "${QA_DB_NAME:-repasse_qa}" ]] || fail 'Restauração sobre o banco original proibida.'
[[ "${QA_RESTORE_CONFIRM:-}" == CREATE_NEW_QA_SCHEMA ]] || fail 'Confirmação explícita CREATE_NEW_QA_SCHEMA obrigatória.'
[[ -n "${QA_APP_PATH:-}" && -d "$QA_APP_PATH" ]] || fail 'QA_APP_PATH inválido.'
for name in QA_DB_PASSWORD QA_DB_ROOT_PASSWORD QA_APP_KEY QA_JWT_SECRET; do
  [[ -n "${!name:-}" ]] || fail "$name ausente do ambiente."
done
bash ops/verify-backup.sh "$archive" >/dev/null || fail 'Backup sem integridade confirmada.'
command -v docker >/dev/null 2>&1 || fail 'Docker indisponível.'
docker compose version >/dev/null 2>&1 || fail 'Docker Compose v2 indisponível.'
docker compose -f compose.qa.yaml config --quiet

# Nome do banco validado por regex restritiva, passado como variável ao contêiner.
docker compose -f compose.qa.yaml exec -T -e RESTORE_DB="$QA_RESTORE_DATABASE" mysql sh -ec '
  existing="$(MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root --host=127.0.0.1 --batch --skip-column-names -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '\''$RESTORE_DB'\''")"
  [ -z "$existing" ] || { echo "Destino já existe. Não sobrescrever." >&2; exit 3; }
  MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root --host=127.0.0.1 -e "CREATE DATABASE \`$RESTORE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
'
# Falha no dump deixa o novo schema para inspeção; nunca apaga conteúdo.
gzip -cd -- "$archive" | docker compose -f compose.qa.yaml exec -T -e RESTORE_DB="$QA_RESTORE_DATABASE" mysql sh -ec \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root --host=127.0.0.1 "$RESTORE_DB"'

tables="$(docker compose -f compose.qa.yaml exec -T -e RESTORE_DB="$QA_RESTORE_DATABASE" mysql sh -ec \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root --host=127.0.0.1 --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '\''$RESTORE_DB'\''"' | tr -d '[:space:]')"
[[ "$tables" =~ ^[0-9]+$ && "$tables" -gt 0 ]] || fail 'Ensaio não restaurou tabelas; banco separado foi preservado para diagnóstico.'
printf 'Restauração de ensaio encerrada em banco separado: %s (%s tabelas).\n' "$QA_RESTORE_DATABASE" "$tables"
printf 'O banco QA restaurado foi preservado para auditoria; não houve exclusão automática.\n'
