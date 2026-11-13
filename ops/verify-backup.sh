#!/usr/bin/env bash
# Valida integridade acidental do dump; SHA-256 NÃO comprova autenticidade.
set -Eeuo pipefail
fail() { printf 'BACKUP INVÁLIDO: %s\n' "$1" >&2; exit 2; }
[[ $# -eq 1 ]] || fail 'Informe somente o caminho .sql.gz a verificar.'
archive="$1"
[[ -f "$archive" && ! -L "$archive" && -s "$archive" ]] || fail 'Arquivo ausente, vazio ou symlink.'
[[ "$archive" == *.sql.gz ]] || fail 'Extensão esperada: .sql.gz.'
[[ -f "$archive.sha256" && ! -L "$archive.sha256" ]] || fail 'Checksum ausente ou symlink.'
checksum="$(cat -- "$archive.sha256")"
[[ "$checksum" =~ ^[a-fA-F0-9]{64}$ ]] || fail 'SHA-256 inválido.'
if command -v shasum >/dev/null 2>&1; then
  actual="$(shasum -a 256 -- "$archive" | awk '{print $1}')"
elif command -v sha256sum >/dev/null 2>&1; then
  actual="$(sha256sum -- "$archive" | awk '{print $1}')"
else
  fail 'shasum ou sha256sum indisponível.'
fi
[[ "${actual,,}" == "${checksum,,}" ]] || fail 'Checksum divergente.'
gzip -t -- "$archive" || fail 'Gzip danificado.'
printf 'BACKUP ÍNTEGRO: checksum e gzip válidos (autenticidade não comprovada).\n'
