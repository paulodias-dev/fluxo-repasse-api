# Armazenamento de mídia

A API usa \`MediaStorage\` para manter uploads independentes do provedor físico.
Em produção, o disco primário é o S3; durante a migração, arquivos ainda
existentes no disco local são lidos pelo fallback.

## Configuração

\`\`\`env
MEDIA_DISK=s3
MEDIA_FALLBACK_DISK=local
MEDIA_FALLBACK_DISKS=local,public
MEDIA_MIRROR_DISK=
MEDIA_MIRROR_FAIL_OPEN=true
MEDIA_URL_MODE=temporary
MEDIA_TEMPORARY_URL_MINUTES=60
MEDIA_TEMPORARY_URL_CACHE_MINUTES=55
\`\`\`

O bucket deve permanecer privado. A API entrega documentos por endpoints
autenticados e gera URLs temporárias somente para usos que realmente precisam
de URL direta.

## Validar o S3

O comando grava e remove somente um arquivo próprio de health check:

\`\`\`bash
php artisan media:check --disk=s3
\`\`\`

## Migrar arquivos locais

A sincronização nunca apaga a origem:

\`\`\`bash
php artisan media:sync --from=local --to=s3 --dry-run
php artisan media:sync --from=local --to=s3 --verify
php artisan media:sync --from=public --to=s3 --prefix=avatars --verify
\`\`\`

Depois de verificar os arquivos no S3, mantenha \`MEDIA_FALLBACK_DISK=local\`
durante o período de observação. A remoção do armazenamento local, se desejada,
deve ser uma operação separada e explicitamente autorizada.

Após alterar variáveis de ambiente em produção:

\`\`\`bash
php artisan optimize:clear
php artisan config:cache
\`\`\`
