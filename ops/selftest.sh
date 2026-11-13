#!/usr/bin/env bash
# Verificações sem MySQL: fixtures locais, checksum e bloqueios antes de Docker.
set -Eeuo pipefail
cd "$(dirname "$0")/.."
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
passed=0
failed=0

expect_ok() {
  local label="$1"; shift
  if env -i PATH="$PATH" HOME="${HOME:-}" TMPDIR="${TMPDIR:-/tmp}" "$@" > "$work/out" 2>&1; then
    ((passed+=1)); printf 'OK: %s\n' "$label"
  else
    ((failed+=1)); printf 'FALHA: %s\n' "$label" >&2
    sed -n '1,12p' "$work/out" >&2
  fi
}
expect_block() {
  local label="$1"; shift
  if "$@" > "$work/out" 2>&1; then
    ((failed+=1)); printf 'FALHA: aceitou operação proibida — %s\n' "$label" >&2
  else
    ((passed+=1)); printf 'OK: bloqueou %s\n' "$label"
  fi
}
hash_file() {
  if command -v shasum >/dev/null; then shasum -a 256 -- "$1" | awk '{print $1}'
  else sha256sum -- "$1" | awk '{print $1}'; fi
}

archive="$work/fixture.sql.gz"
printf 'CREATE TABLE apenas_fixture (id INTEGER);\n' | gzip -c > "$archive"
hash_file "$archive" > "$archive.sha256"
expect_ok 'dump comprimido íntegro' bash ops/verify-backup.sh "$archive"

printf '%064d\n' 0 > "$archive.sha256"
expect_block 'checksum adulterado' bash ops/verify-backup.sh "$archive"

printf 'arquivo corrompido\n' > "$archive"
hash_file "$archive" > "$archive.sha256"
expect_block 'gzip danificado com checksum válido' bash ops/verify-backup.sh "$archive"
expect_block 'arquivo ausente' bash ops/verify-backup.sh "$work/not-found.sql.gz"

printf 'fixture\n' > "$work/secret.txt"
ln -s "$work/secret.txt" "$work/link.sql.gz"
hash_file "$work/secret.txt" > "$work/link.sql.gz.sha256"
expect_block 'symlink proibido' bash ops/verify-backup.sh "$work/link.sql.gz"

expect_block 'backup fora de QA' env QA_DB_NAME=production QA_BACKUP_DIR="$work" bash ops/backup-qa.sh
expect_block 'backup sem diretório declarado' env -u QA_BACKUP_DIR bash ops/backup-qa.sh
expect_block 'destino de restauração fora de QA' env QA_RESTORE_DATABASE=production bash ops/restore-qa.sh "$archive"
expect_block 'restauração sem confirmação' env QA_RESTORE_DATABASE=recovery_restore_qa bash ops/restore-qa.sh "$archive"
expect_block 'restauração sem clone do APP' env -u QA_APP_PATH QA_RESTORE_DATABASE=recovery_restore_qa QA_RESTORE_CONFIRM=CREATE_NEW_QA_SCHEMA bash ops/restore-qa.sh "$archive"

# Simula apenas a saída de um mysqldump; nenhum Docker ou MySQL verdadeiro é acionado.
mkdir -p "$work/bin" "$work/cloned-app"
cat > "$work/bin/docker" <<'MOCK'
#!/usr/bin/env bash
case "$*" in
  *"exec -T mysql"*) printf 'CREATE TABLE fixture_do_mock (id INTEGER);\n' ;;
  *) exit 0 ;;
esac
MOCK
chmod 700 "$work/bin/docker"
expect_block 'diretório de backup dentro do repositório' env PATH="$work/bin:$PATH" QA_APP_PATH="$work/cloned-app" QA_BACKUP_DIR="$PWD" QA_DB_NAME=repasse_qa QA_DB_PASSWORD=qa QA_DB_ROOT_PASSWORD=qa QA_APP_KEY=base64:qa QA_JWT_SECRET=qa bash ops/backup-qa.sh
expect_ok 'backup gerado pelo fluxo com Docker simulado' env PATH="$work/bin:$PATH" QA_APP_PATH="$work/cloned-app" QA_BACKUP_DIR="$work/backups" QA_DB_NAME=repasse_qa QA_DB_PASSWORD=qa QA_DB_ROOT_PASSWORD=qa QA_APP_KEY=base64:qa QA_JWT_SECRET=qa bash ops/backup-qa.sh
archives=( "$work/backups/"*.sql.gz )
if [[ "${#archives[@]}" -eq 1 && -f "${archives[0]}" ]]; then
  expect_ok 'artefato do fluxo possui gzip e SHA-256 válidos' bash ops/verify-backup.sh "${archives[0]}"
else
  ((failed+=1)); printf 'FALHA: backup simulado não produziu um único arquivo\n' >&2
fi

printf 'Selftest operacional: %d testes aprovados, %d falhas. Não executou MySQL real.\n' "$passed" "$failed"
[[ "$failed" -eq 0 && "$passed" -eq 13 ]]
