#!/usr/bin/env bash
# Backup manual apenas do MySQL do Compose de homologação. Não publica dump ou segredos.
set -Eeuo pipefail
umask 077
cd "$(dirname "$0")/.."
fail() { printf 'BACKUP BLOQUEADO: %s\n' "$1" >&2; exit 2; }

[[ "${QA_DB_NAME:-repasse_qa}" =~ ^[a-zA-Z0-9_]+_qa$ ]] || fail 'Banco precisa terminar em _qa.'
[[ -n "${QA_BACKUP_DIR:-}" && "${QA_BACKUP_DIR}" == /* ]] || fail 'QA_BACKUP_DIR deve ser caminho absoluto.'
[[ -n "${QA_APP_PATH:-}" && -d "${QA_APP_PATH}" ]] || fail 'QA_APP_PATH deve apontar para o clone do APP.'
for name in QA_DB_PASSWORD QA_DB_ROOT_PASSWORD QA_APP_KEY QA_JWT_SECRET; do
  [[ -n "${!name:-}" ]] || fail "$name ausente do ambiente de QA."
done
command -v docker >/dev/null 2>&1 || fail 'Docker indisponível.'
docker compose version >/dev/null 2>&1 || fail 'Docker Compose v2 indisponível.'

[[ ! -L "$QA_BACKUP_DIR" ]] || fail 'Diretório não pode ser symlink.'
mkdir -p -- "$QA_BACKUP_DIR"
chmod 700 -- "$QA_BACKUP_DIR"
[[ -d "$QA_BACKUP_DIR" && -w "$QA_BACKUP_DIR" ]] || fail 'Diretório não gravável.'
tmp="$(mktemp "$QA_BACKUP_DIR/.repasse-XXXXXX.sql.gz")"
trap 'rm -f -- "$tmp"' EXIT
archive="$QA_BACKUP_DIR/fluxo-repasse-${QA_DB_NAME:-repasse_qa}-$(date -u +%Y%m%dT%H%M%SZ)-$$.sql.gz"
[[ ! -e "$archive" && ! -e "$archive.sha256" ]] || fail 'Backup com mesmo nome já existe.'

# MYSQL_PWD usa segredo já injetado dentro do contêiner; nunca na linha de comando do host.
# Sem --databases, CREATE DATABASE ou USE; restauração somente em schema QA novo.
docker compose -f compose.qa.yaml config --quiet
docker compose -f compose.qa.yaml exec -T mysql sh -ec \
  'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump --user="$MYSQL_USER" --host=127.0.0.1 --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF --skip-add-drop-table --skip-comments "$MYSQL_DATABASE"' \
  | gzip -9 > "$tmp"
[[ -s "$tmp" ]] || fail 'Dump vazio.'
gzip -t -- "$tmp" || fail 'Dump gzip inválido.'
mv -- "$tmp" "$archive"
if command -v shasum >/dev/null 2>&1; then
  digest="$(shasum -a 256 -- "$archive" | awk '{print $1}')"
else
  digest="$(sha256sum -- "$archive" | awk '{print $1}')"
fi
printf '%s\n' "$digest" > "$archive.sha256"
chmod 600 -- "$archive" "$archive.sha256"
bash ops/verify-backup.sh "$archive" >/dev/null
printf 'Backup QA criado: %s\n' "$archive"
printf 'ATENÇÃO: contém dados pessoais em arquivo local não criptografado. Não publicar e aplicar retenção restrita.\n'
