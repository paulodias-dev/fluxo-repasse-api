#!/usr/bin/env bash
# Resolução explícita de dependências reais em contêiner QA. Não executa plugins ou scripts.
set -Eeuo pipefail
cd "$(dirname "$0")/.."

fail() { printf 'BLOQUEADO: %s\n' "$1" >&2; exit 2; }
[[ ! -e composer.lock ]] || fail 'composer.lock já existe. Revise antes de atualizar.'
[[ -f composer.json ]] || fail 'composer.json não encontrado.'
command -v docker >/dev/null || fail 'Docker indisponível; não é possível resolver dependências reais.'
docker info >/dev/null 2>&1 || fail 'Docker Engine indisponível.'

image=fluxo-repasse-qa-composer:local
docker build -f qa/Dockerfile.api -t "$image" .
docker run --rm --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer \
  -v "$PWD:/workspace/api" -w /workspace/api "$image" \
  composer update --no-install --no-interaction --no-plugins --no-scripts --prefer-dist --no-progress

[[ -s composer.lock ]] || fail 'Composer não produziu um lockfile válido.'
docker run --rm -v "$PWD:/workspace/api:ro" -w /workspace/api "$image" composer validate --strict --no-interaction
docker run --rm -v "$PWD:/workspace/api:ro" -w /workspace/api "$image" php -r '
  $lock=json_decode(file_get_contents("composer.lock"), true, 512, JSON_THROW_ON_ERROR);
  if (!isset($lock["content-hash"], $lock["packages"], $lock["packages-dev"])) { exit(1); }
'
printf 'composer.lock gerado via Composer real. Revise o diff, as licenças e CVEs antes do commit.\n'
