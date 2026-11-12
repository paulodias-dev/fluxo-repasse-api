# Publicação e operação — API

## Checklist antes do primeiro deploy
- Escopo e threat model validados; nenhum endpoint financeiro não aprovado.
- `composer.lock` gerado e revisado; `composer install --no-dev --prefer-dist --optimize-autoloader`.
- Secrets por gerenciador seguro; `APP_KEY` persistente e protegida; `APP_DEBUG=false`; `APP_ENV=production`.
- Infra PHP-FPM e web server apontando exclusivamente para `public/`; bloquear `.env`.
- Banco MySQL com backup e teste de restauração, usuário exclusivo, TLS quando aplicável.
- HTTPS + CORS_ALLOW​ED_ORIGINS com origens reais sem wildcard (a chave correta é `CORS_ALLOWED_ORIGINS`).
- `php artisan config:cache`, `php artisan route:cache`; migrations versionadas após backup e janela segura.
- Permissão de escrita limitada a `storage/` e `bootstrap/cache/`.
- Monitoramento de `/up`, latência `/api/v1/health`, logs centralizados e alertas.
- Queue worker supervisionado somente quando jobs de negócio forem aprovados.

## Rollback
Registrar SHA, snapshot/backup validado, plano de rollback para alterações não retrocompatíveis e migrações aditivas sempre que possível.
Nunca rodar `php artisan migrate:fresh` em produção.
